<?php

namespace App\Modules\Commercial\Presentation;

use App\Modules\Commercial\Application\AgreementWorkflow;
use Illuminate\Http\Request;

final class CommercialController
{
    public function __construct(private AgreementWorkflow $work) {}

    public function index(Request $r, string $organization): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1']);

        return response()->json($this->work->index($r->user(), $organization))->header('Cache-Control', 'private, no-store');
    }

    public function options(Request $r, string $organization): mixed
    {
        $v = $r->validate(['order_id' => 'nullable|ulid', 'q' => 'nullable|string|max:80']);

        return response()->json($this->work->options($r->user(), $organization, $v['order_id'] ?? null, $v['q'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $organization, string $agreement): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1', 'payments_page' => 'sometimes|integer|min:1']);

        return response()->json($this->work->show($r->user(), $organization, $agreement))->header('Cache-Control', 'private, no-store');
    }

    public function propose(Request $r, string $organization): mixed
    {
        $kind = $r->validate(['kind' => 'required|in:consignment,sponsored_promo'])['kind'];
        $common = ['operation_id' => 'required|uuid', 'kind' => 'required|in:consignment,sponsored_promo', 'contract_id' => 'required|ulid', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'reference' => 'required|string|max:4000'];
        $terms = $kind === 'consignment' ? [
            'order_id' => 'required|ulid', 'terms' => 'required|array:basis,supplier_basis_points,tax_basis_points,loss_policy,bundle_basis,costs,rounding,refund_policy,frequency,data_scope,movement_policy',
            'terms.basis' => 'required|in:net_sales,margin', 'terms.supplier_basis_points' => 'required|integer|min:0|max:10000', 'terms.tax_basis_points' => 'required|integer|min:0|max:10000', 'terms.loss_policy' => 'required|in:zero_floor,share_loss', 'terms.bundle_basis' => 'required|in:reject,piece_proportional', 'terms.costs' => 'present|array|max:100', 'terms.costs.*' => 'array:order_line_id,cost_per_piece,evidence_reference', 'terms.costs.*.order_line_id' => 'required|ulid|distinct', 'terms.costs.*.cost_per_piece' => 'required|integer|min:0|max:1000000000', 'terms.costs.*.evidence_reference' => 'required|string|max:2000', 'terms.rounding' => 'required|in:floor_magnitude_per_line', 'terms.refund_policy' => 'required|in:reverse_proportionally', 'terms.frequency' => 'required|in:continuous', 'terms.data_scope' => 'required|in:allocated_receipt_totals', 'terms.movement_policy' => 'required|in:original_receipt_store_only',
        ] : [
            'terms' => 'required|array:sponsor_basis_points,budget_amount,budget_restore,voucher,data_scope', 'terms.sponsor_basis_points' => 'required|integer|min:1|max:10000', 'terms.budget_amount' => 'required|integer|min:1|max:1000000000000', 'terms.budget_restore' => 'required|in:never,proportional_refund', 'terms.data_scope' => 'required|in:allocated_receipt_totals',
            'terms.voucher' => 'required|array:code,kind,value,maximum_discount,minimum_subtotal,usage_limit,store_ids,product_ids,restore_policy,terms', 'terms.voucher.code' => 'required|string|max:60|regex:/^[A-Z0-9_-]+$/', 'terms.voucher.kind' => 'required|in:fixed,percent', 'terms.voucher.value' => 'required|integer|min:1|max:1000000000000', 'terms.voucher.maximum_discount' => 'required|integer|min:1|max:1000000000000', 'terms.voucher.minimum_subtotal' => 'required|integer|min:0|max:1000000000000', 'terms.voucher.usage_limit' => 'required|integer|min:1|max:1000000', 'terms.voucher.store_ids' => 'required|array|min:1|max:100', 'terms.voucher.store_ids.*' => 'required|ulid|distinct', 'terms.voucher.product_ids' => 'present|array|max:100', 'terms.voucher.product_ids.*' => 'required|ulid|distinct', 'terms.voucher.restore_policy' => 'required|in:never,on_full_refund', 'terms.voucher.terms' => 'required|string|max:2000',
        ];
        $v = $r->validate([...$common, ...$terms]);
        if ($kind === 'sponsored_promo' && $v['terms']['voucher']['kind'] === 'percent') {
            abort_if((int) $v['terms']['voucher']['value'] > 10000, 422);
        }
        // Validated HTML numbers may arrive as strings; freeze canonical integer terms.
        foreach (['supplier_basis_points', 'tax_basis_points', 'sponsor_basis_points', 'budget_amount'] as $k) {
            if (isset($v['terms'][$k])) {
                $v['terms'][$k] = (int) $v['terms'][$k];
            }
        }
        foreach ($v['terms']['costs'] ?? [] as $i => $row) {
            $v['terms']['costs'][$i]['cost_per_piece'] = (int) $row['cost_per_piece'];
        }
        foreach (['value', 'maximum_discount', 'minimum_subtotal', 'usage_limit'] as $k) {
            if (isset($v['terms']['voucher'][$k])) {
                $v['terms']['voucher'][$k] = (int) $v['terms']['voucher'][$k];
            }
        }

        return response()->json(['data' => $this->work->propose($r->user(), $organization, $v)], 201);
    }

    public function decide(Request $r, string $organization, string $agreement, string $action): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'consent' => 'sometimes|boolean', 'sharing_consented' => 'sometimes|boolean']);

        return ['data' => $this->work->decide($r->user(), $organization, $agreement, $action, $v)];
    }

    public function payment(Request $r, string $organization, string $agreement): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'direction' => 'required|in:collection,payout', 'amount' => 'required|integer|min:1|max:1000000000000', 'method' => 'required|in:bank_transfer,cash', 'reference' => 'required|string|max:160', 'evidence_reference' => 'required|string|max:2000', 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:'.now('Asia/Jakarta')->format('Y-m-d'), 'verified' => 'required|boolean']);

        return ['data' => $this->work->payment($r->user(), $organization, $agreement, $v)];
    }
}
