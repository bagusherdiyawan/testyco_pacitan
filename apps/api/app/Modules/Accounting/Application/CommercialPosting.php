<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Commercial\Infrastructure\CommercialAgreement;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleRefund;
use Illuminate\Support\Facades\DB;

/** Posts contractual settlement rights, separate from ownership cost and POS accounting. */
final class CommercialPosting
{
    private function policy(string $org, ?string $id = null): ?object
    {
        $q = DB::table('accounting_policies as p')->join('accounting_commercial_policies as c', 'c.policy_id', '=', 'p.id')->where('p.organization_id', $org)->where('c.organization_id', $org)->whereNotNull('p.activated_at');

        return ($id ? $q->where('p.id', $id) : $q->where('p.status', 'active'))->select('p.*', 'c.tax_treatment')->first();
    }

    private function signed(string $debit, string $credit, int $n): array
    {
        return $n >= 0 ? [$debit, $credit, $n] : [$credit, $debit, -$n];
    }

    public function entry(CommercialAgreement $g, string $entry, string $position, int $version, Sale $sale, int $amount, int $tax, ?int $previousSaleVersion = null, int $refundQuantity = 0): void
    {
        $day = $sale->sold_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d');
        $sourceReason = null;
        if ($version === 1 && $refundQuantity > 0) {
            $sourceReason = 'Akumulasi pertama sudah memuat refund; rekonsiliasi tanggal sumber diperlukan.';
        }
        if ($version > 1) {
            $changes = $sale->version - ($previousSaleVersion ?? $sale->version);
            $refunds = SaleRefund::where('sale_id', $sale->id)->orderByDesc('id')->limit(min(1001, max(1, $changes)))->get();
            $days = $refunds->map(fn ($r) => $r->refunded_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d'))->unique();
            if ($changes < 1 || $changes > 1000 || $refunds->count() !== $changes || $days->count() !== 1) {
                $sourceReason = 'Perubahan posisi menggabungkan beberapa tanggal atau versi sumber tidak terbukti; rekonsiliasi diperlukan.';
                $day = min($day, $days->min() ?? $day);
            } else {
                $day = $days->first();
            }
        }
        foreach ([$g->organization_id, $g->partner_id] as $org) {
            $original = DB::table('accounting_commercial_positions')->where('organization_id', $org)->where('commercial_position_id', $position)->first();
            $p = $this->policy($org, $original?->policy_id);
            if (! $p) {
                continue;
            }
            $reason = $sourceReason;
            if (! $original && ($version !== 1 || $sale->sold_at->lt($p->activated_at))) {
                $reason = 'Posisi komersial berasal sebelum aktivasi; rekonsiliasi cutover diperlukan.';
            }
            if (! $original && ! $reason) {
                DB::table('accounting_commercial_positions')->insert(['organization_id' => $org, 'commercial_position_id' => $position, 'commercial_agreement_id' => $g->id, 'policy_id' => $p->id, 'created_at' => now()]);
            }
            if ($amount === 0 && $tax === 0) {
                continue; // A zero delta has no journal, even when historical scope is reviewed.
            }
            if ($original) {
                $unsupported = DB::table('commercial_entries as ce')->leftJoin('accounting_events as ae', function ($join) use ($org) {
                    $join->on('ae.source_id', '=', 'ce.id')->where('ae.organization_id', $org)->where('ae.source_type', 'commercial_accrual');
                })->where('ce.commercial_position_id', $position)->where('ce.id', '!=', $entry)->where('ce.amount', '!=', 0)->where(function ($q) {
                    $q->whereNull('ae.id')->orWhere('ae.status', 'reconciled')->orWhere(function ($q) {
                        $q->where('ae.status', 'review_required')->where(fn ($q) => $q->whereNull('ae.reason')->orWhere('ae.reason', 'not like', 'Tanggal sumber%'));
                    });
                })->exists();
                if ($unsupported) {
                    $reason = 'Posisi asal memerlukan rekonsiliasi ekonomi; koreksi tidak boleh melewati sumber tertahan.';
                }
            }
            $supplier = $org === $g->organization_id;
            if ($g->kind === 'sponsored_promo') {
                $terms = [$supplier ? $this->signed('sponsor_expense', 'sponsor_payable', -$amount) : $this->signed('sponsor_receivable', 'sponsor_recovery', -$amount)];
            } elseif ($supplier) {
                $terms = [$this->signed('share_receivable', 'share_income', $amount - $tax), $this->signed('share_receivable', 'share_output_tax', $tax)];
            } else {
                $terms = [$this->signed('share_expense', 'share_payable', $amount - $tax), $this->signed($p->tax_treatment === 'recoverable' ? 'share_input_tax' : 'share_expense', 'share_payable', $tax)];
            }
            app(AutomaticPosting::class)->record($org, $sale->cashier_id, 'commercial_accrual', $entry, $day, $terms, 'Kesepakatan '.$g->id.' · posisi '.$position.' versi'.$version, $reason, $p->id);
        }
    }

    public function payment(CommercialAgreement $g, array $payment): void
    {
        foreach ([$g->organization_id, $g->partner_id] as $org) {
            $origins = DB::table('accounting_commercial_positions')->where('organization_id', $org)->where('commercial_agreement_id', $g->id)->distinct()->pluck('policy_id');
            $p = $origins->count() === 1 ? $this->policy($org, $origins->first()) : $this->policy($org);
            if (! $p) {
                continue;
            }
            $unposted = DB::table('commercial_entries')->where('commercial_agreement_id', $g->id)->where('amount', '!=', 0)->whereNotIn('id', DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'commercial_accrual')->whereIn('status', ['pending', 'posted'])->select('source_id'))->exists();
            $reason = $origins->count() !== 1 || $unposted ? 'Pelunasan komersial mempunyai saldo/pemetaan sebelum aktivasi atau campuran; rekonsiliasi diperlukan.' : null;
            $n = (int) $payment['signed_amount'];
            $cash = $payment['method'] === 'cash' ? 'cash' : 'bank';
            $supplier = $org === $g->organization_id;
            if ($g->kind === 'sponsored_promo') {
                $terms = [$supplier ? $this->signed('sponsor_payable', $cash, -$n) : $this->signed($cash, 'sponsor_receivable', -$n)];
            } else {
                $terms = [$supplier ? $this->signed($cash, 'share_receivable', $n) : $this->signed('share_payable', $cash, $n)];
            }
            app(AutomaticPosting::class)->record($org, (int) $payment['verified_by'], 'commercial_payment', $payment['id'], $payment['paid_on'], $terms, 'Pembayaran komersial '.$g->id.' · '.$payment['reference'], $reason, $p->id);
        }
    }
}
