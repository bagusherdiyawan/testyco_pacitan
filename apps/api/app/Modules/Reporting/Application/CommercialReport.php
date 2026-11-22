<?php

namespace App\Modules\Reporting\Application;

use App\Models\User;
use App\Modules\Billing\Application\CommercialBalances;
use App\Modules\Billing\Infrastructure\Invoice;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleRefund;
use App\Shared\Application\BoundedExport;
use App\Shared\Application\Operations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommercialReport
{
    public function __construct(private Access $access, private CommercialBalances $billing, private Operations $ops) {}

    private function manager(User $u, string $org): void
    {
        abort_unless(in_array($this->access->role($u, $org)?->value, ['owner', 'admin', 'partner_lead'], true), 403, 'Laporan keuangan hanya pengelola organisasi sendiri.');
    }

    public function declare(User $u, string $org, array $v): array
    {
        $this->manager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'report.cost:'.$v['source_id'], $v, function () use ($u, $org, $v) {
            $this->manager($u, $org);
            $record = $v['source_type'] === 'pos_sale' ? Sale::where('organization_id', $org)->lockForUpdate()->findOrFail($v['source_id']) : Invoice::where('organization_id', $org)->where('status', 'accepted')->lockForUpdate()->findOrFail($v['source_id']);
            $latest = DB::table('cost_declarations')->where('organization_id', $org)->where('source_type', $v['source_type'])->where('source_id', $record->id)->max('version') ?? 0;
            abort_unless((int) $latest === (int) $v['version'], 409, 'Versi bukti biaya berubah.');
            $d = ['id' => (string) Str::ulid(), 'organization_id' => $org, 'source_type' => $v['source_type'], 'source_id' => $record->id, 'version' => (int) $latest + 1, 'goods_cost' => (int) $v['goods_cost'], 'operating_cost' => (int) $v['operating_cost'], 'evidence_reference' => $v['evidence_reference'], 'reason' => $v['reason'], 'recorded_by' => $u->id, 'recorded_at' => now()->format('Y-m-d H:i:s')];
            DB::table('cost_declarations')->insert($d);
            Audit::record($u->id, $org, 'report.cost_declared', $d['id'], null, $d);

            return $d;
        });
    }

    public function read(User $u, string $org, array $v): array
    {
        $this->manager($u, $org);
        $from = CarbonImmutable::parse($v['from'])->utc();
        $to = CarbonImmutable::parse($v['to'])->utc();
        abort_unless($to->timestamp > $from->timestamp && $to->timestamp - $from->timestamp <= 31 * 86400, 422, 'Rentang maksimal31hari.');
        $side = Organization::findOrFail($org)->type === 'center' ? 'seller' : 'buyer';
        $observed = now()->toIso8601String();
        $sales = Sale::where('organization_id', $org)->where('sold_at', '>=', $from)->where('sold_at', '<', $to)->orderBy('id')->limit(10001)->get();
        $invoices = Invoice::where($side === 'seller' ? 'organization_id' : 'partner_id', $org)->where('status', 'accepted')->where('decided_at', '>=', $from)->where('decided_at', '<', $to)->orderBy('id')->limit(10001)->get();
        abort_if($sales->count() + $invoices->count() > 10000, 422, 'Laporan maksimal10.000dokumen. Persempit periode.');
        $refunds = SaleRefund::whereIn('sale_id', $sales->pluck('id'))->selectRaw('sale_id,SUM(amount) as amount')->groupBy('sale_id')->get()->keyBy('sale_id');
        $costs = DB::table('cost_declarations')->where('organization_id', $org)->whereIn('source_id', [...$sales->pluck('id'), ...$invoices->pluck('id')])->orderBy('version')->get()->keyBy(fn ($c) => $c->source_type.':'.$c->source_id);
        $rows = [];
        foreach ($sales as $sale) {
            $refund = (int) ($refunds->get($sale->id)?->amount ?? 0);
            $rows[] = $this->row($org, 'pos_sale', $sale->id, $sale->number, $sale->sold_at->toIso8601String(), $sale->store_id, $sale->payment_method, $sale->subtotal, $sale->discount_amount, $refund, $sale->total_amount - $refund, $costs->get('pos_sale:'.$sale->id));
        }
        $invoiceBalances = $this->billing->forInvoices($invoices->pluck('id')->all());
        $balances = ['outstanding_amount' => 0, 'customer_credit_amount' => 0, 'overdue_amount' => 0];
        foreach ($invoices as $i) {
            $b = $invoiceBalances[$i->id];
            $credit = $b['base_credit'];
            $signed = $i->total_amount - $b['credit_amount'] - $b['collected_amount'] + $b['refunded_amount'];
            $b = [...$b, 'signed_balance' => $signed, 'outstanding_amount' => max(0, $signed), 'customer_credit_amount' => max(0, -$signed)];
            unset($b['base_credit']);
            $net = $i->subtotal - $i->discount_amount - $credit;
            $r = $this->row($org, $side === 'seller' ? 'wholesale_invoice' : 'purchase_invoice', $i->id, $i->number, $i->decided_at->toIso8601String(), null, $i->mode, $i->subtotal, $i->discount_amount, $credit, $net, $side === 'seller' ? $costs->get('wholesale_invoice:'.$i->id) : null);
            $r = [...$r, ...$b, 'partner_id' => $side === 'seller' ? $i->partner_id : $i->organization_id, 'due_on' => $i->due_on->format('Y-m-d'), 'tax_amount' => $i->tax_amount, 'shipping_amount' => $i->shipping_amount];
            $rows[] = $r;
            $balances['outstanding_amount'] += $b['outstanding_amount'];
            $balances['customer_credit_amount'] += $b['customer_credit_amount'];
            if ($i->due_on->format('Y-m-d') < now('Asia/Jakarta')->format('Y-m-d')) {
                $balances['overdue_amount'] += $b['outstanding_amount'];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a['occurred_at'].'|'.$a['id'], $b['occurred_at'].'|'.$b['id']));
        $rows = BoundedExport::collect($rows, 'laporan komersial');
        $metrics = [];
        foreach (['pos_sale', 'wholesale_invoice', 'purchase_invoice'] as $type) {
            $group = collect($rows)->where('source_type', $type);
            $known = $group->whereNotNull('goods_cost');
            $metrics[$type] = ['document_count' => $group->count(), 'gross_amount' => (int) $group->sum('gross_amount'), 'discount_amount' => (int) $group->sum('discount_amount'), 'refund_credit_amount' => (int) $group->sum('refund_credit_amount'), 'net_goods_amount' => (int) $group->sum('net_goods_amount'), 'costed_document_count' => $known->count(), 'goods_cost' => (int) $known->sum('goods_cost'), 'operating_cost' => (int) $known->sum('operating_cost'), 'covered_profit_amount' => (int) $known->sum('profit_amount'), 'profit_amount' => $type === 'purchase_invoice' || $known->count() !== $group->count() ? null : (int) $known->sum('profit_amount')];
        }

        return ['organization_id' => $org, 'side' => $side, 'currency' => 'IDR', 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'observed_at' => $observed, 'basis' => 'document_cohort_current_adjustments', 'balances_scope' => 'accepted_invoices_in_selected_period', 'metrics' => $metrics, 'balances' => $balances, 'data' => $rows];
    }

    private function row(string $org, string $type, string $id, string $number, string $at, ?string $store, string $method, int $gross, int $discount, int $refund, int $net, mixed $cost): array
    {
        return ['organization_id' => $org, 'source_type' => $type, 'id' => $id, 'number' => $number, 'occurred_at' => $at, 'store_id' => $store, 'method' => $method, 'gross_amount' => $gross, 'discount_amount' => $discount, 'refund_credit_amount' => $refund, 'net_goods_amount' => $net, 'goods_cost' => $cost ? (int) $cost->goods_cost : null, 'operating_cost' => $cost ? (int) $cost->operating_cost : null, 'profit_amount' => $cost ? $net - (int) $cost->goods_cost - (int) $cost->operating_cost : null, 'cost_version' => $cost ? (int) $cost->version : 0, 'cost_evidence' => $cost?->evidence_reference];
    }
}
