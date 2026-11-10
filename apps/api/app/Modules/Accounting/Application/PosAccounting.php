<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleRefund;
use Illuminate\Support\Facades\DB;

final class PosAccounting
{
    public function sale(Sale $s): void
    {
        $allocations = DB::table('sale_allocations')->join('sale_lines', 'sale_lines.id', '=', 'sale_allocations.sale_line_id')->where('sale_lines.sale_id', $s->id)->select('sale_allocations.*')->get();
        $terms = [[$s->payment_method === 'cash' ? 'cash' : 'bank', 'sales', $s->total_amount], ['discount', 'sales', $s->discount_amount]];
        $reason = null;
        foreach ($allocations as $a) {
            $cost = DB::table('inventory_cost_entries')->where('organization_id', $s->organization_id)->where('source_type', 'pos_sale')->where('source_id', $a->id)->first();
            $terms[] = ['cogs', 'finished', $cost?->value_delta === null ? null : -(int) $cost->value_delta];
            if ($a->commercial_origin_snapshot !== null) {
                $reason = 'Stok konsinyasi memerlukan rekonsiliasi kepemilikan dan settlement komersial.';
            }
        }
        $policy = DB::table('accounting_policies')->where('organization_id', $s->organization_id)->where('status', 'active')->first();
        if (isset($s->voucher_snapshot['commercial_agreement_id']) && (! $policy || ! DB::table('accounting_commercial_policies')->where('organization_id', $s->organization_id)->where('policy_id', $policy->id)->exists())) {
            $reason = 'Pendanaan promo lintas organisasi memerlukan pemetaan GL sponsor yang disetujui.';
        }
        if ($s->offline_sale_id && $policy && $s->sold_at->lt($policy->activated_at)) {
            $reason = 'Penjualan offline terjadi sebelum aktivasi; rekonsiliasi cutover diperlukan.';
        }
        app(AutomaticPosting::class)->record($s->organization_id, $s->cashier_id, 'pos_sale', $s->id, $s->sold_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d'), $terms, $s->number, $reason);
    }

    public function refund(Sale $s, SaleRefund $refund, array $sourceIds): void
    {
        $terms = [['refund', $s->payment_method === 'cash' ? 'cash' : 'bank', $refund->amount]];
        foreach ($sourceIds as $id) {
            $c = DB::table('inventory_cost_entries')->where('organization_id', $s->organization_id)->where('source_type', 'sale_refund')->where('source_id', $id)->first();
            $terms[] = ['finished', 'cogs', $c?->value_delta === null ? null : (int) $c->value_delta];
        }
        $origin = DB::table('accounting_events')->where('organization_id', $s->organization_id)->where('source_type', 'pos_sale')->where('source_id', $s->id)->first();
        $sponsorCovered = $origin && in_array($origin->status, ['pending', 'posted'], true) && DB::table('accounting_commercial_policies')->where('organization_id', $s->organization_id)->where('policy_id', $origin->policy_id)->exists() && DB::table('accounting_commercial_positions')->where('organization_id', $s->organization_id)->whereIn('commercial_position_id', DB::table('commercial_positions')->where('sale_id', $s->id)->where('source_key', 'promo:'.$s->id)->select('id'))->exists();
        $commercial = (isset($s->voucher_snapshot['commercial_agreement_id']) && ! $sponsorCovered) || DB::table('sale_allocations')->join('sale_lines', 'sale_lines.id', '=', 'sale_allocations.sale_line_id')->where('sale_lines.sale_id', $s->id)->whereNotNull('sale_allocations.commercial_origin_snapshot')->exists();
        $reason = ! $origin ? 'Refund sumber sebelum aktivasi memerlukan rekonsiliasi cutover.' : ($commercial ? 'Refund sumber komersial memerlukan rekonsiliasi settlement/promo.' : null);
        app(AutomaticPosting::class)->record($s->organization_id, $refund->actor_id, 'pos_refund', $refund->id, $refund->refunded_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d'), $terms, $refund->reference, $reason, $origin && in_array($origin->status, ['pending', 'posted'], true) ? $origin->policy_id : null);
    }
}
