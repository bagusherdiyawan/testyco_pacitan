<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Billing\Infrastructure\Invoice;
use App\Modules\Billing\Infrastructure\InvoiceCredit;
use App\Modules\Billing\Infrastructure\InvoicePayment;
use Illuminate\Support\Facades\DB;

final class BillingPosting
{
    private function policy(string $org, ?object $origin = null): ?object
    {
        $q = DB::table('accounting_policies as p')->join('accounting_wholesale_policies as w', 'w.policy_id', '=', 'p.id')->where('p.organization_id', $org)->where('w.organization_id', $org)->whereNotNull('p.activated_at');

        return ($origin ? $q->where('p.id', $origin->policy_id) : $q->where('p.status', 'active'))->select('p.id', 'w.tax_treatment', 'w.freight_treatment')->first();
    }

    private function origin(string $org, string $type, string $id): ?object
    {
        return DB::table('accounting_events')->where('organization_id', $org)->where('source_type', $type)->where('source_id', $id)->first();
    }

    private function reason(Invoice $i, ?object $origin, bool $new = false): ?string
    {
        if ($i->mode !== 'buyout') {
            return 'Invoice konsinyasi memerlukan rekonsiliasi kepemilikan/komisi; bukan penjualan putus beli otomatis.';
        }
        if (! $new && ! $origin) {
            return 'Dokumen asal sebelum aktivasi wholesale; rekonsiliasi cutover diperlukan.';
        }
        if ($origin && $origin->status === 'reconciled') {
            return 'Dokumen asal direkonsiliasi manual; koreksi memerlukan rekonsiliasi sumber.';
        }

        return null;
    }

    public function invoice(Invoice $i, int $actor): void
    {
        foreach ([$i->organization_id, $i->partner_id] as $org) {
            $p = $this->policy($org);
            if (! $p) {
                continue;
            }
            $receiptReason = null;
            if ($org === $i->partner_id && $i->mode === 'buyout') {
                $ids = array_column($i->lines, 'shipment_line_id');
                $receipts = DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'distribution_receipt')->whereIn('source_id', $ids)->get();
                $policies = $receipts->pluck('policy_id')->unique();
                if ($policies->count() === 1) {
                    $p = $this->policy($org, $receipts->first()) ?? $p;
                } elseif ($policies->count() > 1) {
                    $receiptReason = 'Invoice mencakup penerimaan dengan pemetaan berbeda; rekonsiliasi akun penampung diperlukan.';
                }
            }
            if ($org === $i->organization_id) {
                $terms = [['trade_receivable', 'wholesale_sales', $i->subtotal - $i->discount_amount], ['wholesale_discount', 'wholesale_sales', $i->discount_amount], ['trade_receivable', 'shipping_revenue', $i->shipping_amount], ['trade_receivable', 'output_tax', $i->tax_amount]];
            } else {
                $terms = [['purchase_clearing', 'trade_payable', $i->subtotal - $i->discount_amount], [$p->freight_treatment === 'expense' ? 'freight_expense' : 'purchase_clearing', 'trade_payable', $i->shipping_amount], [$p->tax_treatment === 'recoverable' ? 'input_tax' : 'purchase_clearing', 'trade_payable', $i->tax_amount]];
            }
            app(AutomaticPosting::class)->record($org, $actor, 'wholesale_invoice', $i->id, $i->decided_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d'), $terms, $i->number, $receiptReason ?? $this->reason($i, null, true), $p->id);
        }
    }

    public function payment(Invoice $i, InvoicePayment $payment, int $actor, bool $reverse = false): void
    {
        foreach ([$i->organization_id, $i->partner_id] as $org) {
            $origin = $this->origin($org, 'wholesale_invoice', $i->id);
            $originalPayment = $reverse ? $this->origin($org, 'wholesale_payment', $payment->id) : null;
            $p = $this->policy($org, $originalPayment ?? $origin);
            if (! $p) {
                continue;
            }
            $cash = $payment->method === 'cash' ? 'cash' : 'bank';
            $collection = $payment->kind === 'collection';
            $terms = $org === $i->organization_id ? ($collection ? [[$cash, 'trade_receivable', $payment->amount]] : [['trade_receivable', $cash, $payment->amount]]) : ($collection ? [['trade_payable', $cash, $payment->amount]] : [[$cash, 'trade_payable', $payment->amount]]);
            $reason = $this->reason($i, $origin);
            if ($reverse) {
                if ($originalPayment) {
                    $terms = json_decode($originalPayment->terms, true);
                }
                $terms = array_map(fn ($t) => [$t[1], $t[0], $t[2]], $terms);
                if (! $originalPayment || $originalPayment->status === 'reconciled') {
                    $reason = 'Pembayaran asal sebelum aktivasi atau direkonsiliasi manual; pembalikan perlu rekonsiliasi.';
                }
            }
            $day = $reverse ? $payment->reversed_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d') : $payment->paid_on->format('Y-m-d');
            app(AutomaticPosting::class)->record($org, $actor, $reverse ? 'wholesale_payment_reverse' : 'wholesale_payment', $payment->id, $day, $terms, $payment->reference, $reason, $p->id);
        }
    }

    public function credit(Invoice $i, InvoiceCredit $credit, int $actor): void
    {
        foreach ([$i->organization_id, $i->partner_id] as $org) {
            $origin = $this->origin($org, 'wholesale_invoice', $i->id);
            $p = $this->policy($org, $origin);
            if (! $p) {
                continue;
            }
            $terms = $org === $i->organization_id ? [['wholesale_returns', 'trade_receivable', $credit->base_amount], ['output_tax', 'trade_receivable', $credit->tax_amount]] : [['trade_payable', 'purchase_clearing', $credit->base_amount], ['trade_payable', $p->tax_treatment === 'recoverable' ? 'input_tax' : 'purchase_clearing', $credit->tax_amount]];
            app(AutomaticPosting::class)->record($org, $actor, 'wholesale_credit', $credit->id, $credit->decided_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d'), $terms, $i->number.' · '.$credit->reason, $this->reason($i, $origin), $p->id);
        }
    }
}
