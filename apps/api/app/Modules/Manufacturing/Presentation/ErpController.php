<?php

namespace App\Modules\Manufacturing\Presentation;

use App\Modules\Accounting\Application\AutomaticPosting;
use App\Modules\Accounting\Application\CommercialPolicy;
use App\Modules\Accounting\Application\WholesalePolicy;
use App\Modules\Manufacturing\Application\ErpReadModel;
use App\Modules\Manufacturing\Application\ErpWorkflow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ErpController
{
    public function context(Request $r, string $organization, ErpReadModel $read): mixed
    {
        $v = $r->validate(['section' => ['sometimes', Rule::in(ErpReadModel::SECTIONS)], 'page' => 'sometimes|integer|min:1', 'store_id' => 'nullable|ulid', 'material_id' => 'nullable|ulid', 'lot_id' => 'nullable|ulid', 'period_id' => 'nullable|ulid', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);

        return response()->json($read->context($r->user(), $organization, $v))->header('Cache-Control', 'private, no-store');
    }

    public static function rules(string $action): array
    {
        $note = ['note' => 'required|string|max:2000'];
        $key = ['id' => 'required|ulid', 'version' => 'required|integer|min:1'];
        $quantities = ['quantities' => 'required|array|min:1|max:100', 'quantities.*' => 'nullable|string|max:20'];
        $date = 'required|date_format:Y-m-d|before_or_equal:'.now('Asia/Jakarta')->format('Y-m-d');

        return ['operation_id' => 'required|uuid', ...match ($action) {
            'material' => ['code' => 'required|string|max:60', 'name' => 'required|string|max:120', 'unit' => 'required|in:g,kg,ml,l,pcs', 'minimum' => 'required|string|max:20', 'expiry_required' => 'required|boolean', 'fefo_required' => 'required|boolean'],
            'material_configure' => [...$key, 'name' => 'required|string|max:120', 'minimum' => 'required|string|max:20', 'expiry_required' => 'required|boolean', 'fefo_required' => 'required|boolean', 'active' => 'required|boolean'],
            'operator_grant' => ['assignment' => 'required|string|regex:/^[1-9][0-9]*:[0-9A-Z]{26}$/i', 'grant_versions' => 'present|array|max:1000', 'grant_versions.*' => 'required|integer|min:1', 'enabled' => 'required|boolean', ...$note],
            'consignor_enable' => ['agreement_id' => 'required|ulid', ...$note],
            'consignor_retry', 'consignor_dispose' => [...$key, ...$note],
            'missing_propose' => [...$key, 'owner_share_basis_points' => 'required|integer|min:0|max:10000', ...$note],
            'missing_decide' => [...$key, 'decision' => 'required|in:approve,reject', 'consent' => 'required|accepted', ...$note],
            'distribution_retry' => [...$key, ...$note],
            'commercial_policy' => ['base_policy_id' => 'required|ulid', 'base_policy_version' => 'required|integer|min:1', 'recognition_basis' => 'required|in:contractual_rights', 'tax_treatment' => 'required|in:recoverable,expense', ...array_fill_keys(array_map(fn ($role) => $role.'_account', array_keys(CommercialPolicy::ROLES)), 'required|ulid'), ...$note],
            'wholesale_policy' => ['base_policy_id' => 'required|ulid', 'base_policy_version' => 'required|integer|min:1', 'tax_treatment' => 'required|in:recoverable,clearing', 'freight_treatment' => 'required|in:expense,clearing', ...array_fill_keys(array_map(fn ($role) => $role.'_account', array_keys(WholesalePolicy::ROLES)), 'required|ulid'), ...$note],
            'posting_policy' => [...array_fill_keys(array_map(fn ($role) => $role.'_account', array_keys(AutomaticPosting::ROLES)), 'required|ulid'), ...$note],
            'closing_propose' => [...$key, 'retained_account_id' => 'required|ulid', ...$note],
            'closing_decide', 'posting_decide', 'value_decide' => [...$key, 'decision' => 'required|in:approve,reject', ...$note],
            'posting_reconcile' => [...$key, 'journal_id' => 'required|ulid', ...$note],
            'posting_suspend', 'posting_retry', 'invoice_revalue_retry' => [...$key, ...$note],
            'value_propose' => ['batch_id' => 'required|ulid', 'stock_revision' => 'required|integer|min:0', 'amount' => 'required|integer|min:0|max:1000000000000', ...$note],
            'account' => ['code' => 'required|string|max:40', 'name' => 'required|string|max:160', 'kind' => 'required|in:asset,liability,equity,revenue,expense', 'normal_balance' => 'required|in:debit,credit'],
            'accounting_period' => ['name' => 'required|string|max:120', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', ...$note],
            'journal' => ['period_id' => 'required|ulid', 'number' => 'required|string|max:80', 'posted_on' => $date, 'debits' => 'present|array|max:100', 'debits.*' => 'nullable|integer|min:0|max:1000000000000', 'credits' => 'present|array|max:100', 'credits.*' => 'nullable|integer|min:0|max:1000000000000', 'evidence' => 'required|string|max:2000', ...$note],
            'journal_decide' => [...$key, 'decision' => 'required|in:post,reject', ...$note],
            'journal_reverse' => [...$key, 'period_id' => 'required|ulid', 'number' => 'required|string|max:80', 'posted_on' => $date, 'evidence' => 'required|string|max:2000', ...$note],
            'period_status' => [...$key, 'decision' => 'required|in:close,reopen', ...$note],
            'supplier' => ['name' => 'required|string|max:120', 'contact' => 'required|string|max:2000', 'address' => 'required|string|max:2000'],
            'purchase' => ['store_id' => 'required|ulid', 'supplier_id' => 'required|ulid', 'number' => 'required|string|max:80', 'ordered_on' => $date, 'due_on' => 'required|date_format:Y-m-d', ...$quantities, 'amounts' => 'required|array|min:1|max:100', 'amounts.*' => 'nullable|integer|min:0|max:1000000000000', ...$note],
            'purchase_decide' => [...$key, 'decision' => 'required|in:approve,cancel', ...$note],
            'receive' => ['source' => 'required|in:opening,purchase', 'store_id' => 'required|ulid', 'material_id' => 'required|ulid', 'purchase_id' => 'required_if:source,purchase|nullable|ulid', 'version' => 'required_if:source,purchase|nullable|integer|min:1', 'quantity' => 'required|string|max:20', 'batch_code' => 'required|string|max:80', 'received_on' => $date, 'expires_on' => 'nullable|date_format:Y-m-d', 'opening_value' => 'required_if:source,opening|nullable|integer|min:0|max:1000000000000', 'evidence' => 'required|string|max:2000', ...$note],
            'payment_reverse' => [...$key, 'payment_id' => 'required|ulid', 'verified' => 'required|boolean', ...$note],
            'payment' => [...$key, 'kind' => 'sometimes|required|in:payment,supplier_refund', 'verified' => 'required|boolean', 'amount' => 'required|integer|min:1|max:1000000000000', 'method' => 'required|in:cash,bank_transfer', 'reference' => 'required|string|max:160', 'paid_on' => $date, ...$note],
            'recipe' => ['product_id' => 'nullable|ulid', 'output_material_id' => 'nullable|ulid', 'output_quantity' => 'nullable|string|max:20', 'title' => 'required|string|max:160', 'output_packs' => 'required_without:output_material_id|nullable|integer|min:1|max:1000000', 'ingredients' => 'required|array|min:1|max:100', 'ingredients.*' => 'nullable|string|max:20', 'instructions' => 'required|string|max:10000'],
            'production' => ['store_id' => 'required|ulid', 'recipe_id' => 'required|ulid', 'recipe_versions' => 'required|array|min:1|max:1000', 'recipe_versions.*' => 'required|integer|min:1', 'number' => 'required|string|max:80', 'runs' => 'required|integer|min:1|max:10000', ...$note],
            'production_cancel' => [...$key, ...$note],
            'complete' => [...$key, 'allocations' => 'required|array|min:1|max:100', 'allocations.*' => 'nullable|string|max:20', 'allocation_revisions' => 'required|array|min:1|max:1000', 'allocation_revisions.*' => 'required|integer|min:1', 'batch_code' => 'required|string|max:80', 'produced_on' => $date, 'expires_on' => 'required|date_format:Y-m-d', 'good_quantity' => 'nullable|string|max:20', 'failed_quantity' => 'nullable|string|max:20', 'good_packs' => 'nullable|integer|min:0|max:1000000', 'failed_pieces' => 'nullable|integer|min:0|max:10000000', 'labor_cost' => 'required|integer|min:0|max:1000000000000', 'overhead_cost' => 'required|integer|min:0|max:1000000000000', 'cost_allocation' => 'required|in:all_to_good_output', 'cost_evidence' => 'required|string|max:2000', ...$note],
            'material_quality' => [...$key, 'decision' => 'required|in:release,reject,hold,unhold,return', 'quantity' => 'required_unless:decision,hold,unhold|nullable|string|max:20', 'bucket' => 'required_if:decision,return|nullable|in:usable,quarantined', 'supplier_credit' => 'required_if:decision,return|nullable|integer|min:0|max:1000000000000', 'verified' => 'required_if:decision,return|nullable|boolean', ...$note],
            'production_quality' => [...$key, 'stock_revision' => 'required|integer|min:1', 'decision' => 'required|in:release,reject', ...$note],
            'transfer' => [...$key, 'store_id' => 'required|ulid', 'quantity' => 'required|string|max:20', ...$note],
            'count' => [...$key, 'bucket' => 'required|in:usable,quarantined', 'quantity' => 'required|string|max:20', 'adjustment_value' => 'required|integer|min:0|max:1000000000000', ...$note],
            'count_decide' => [...$key, 'decision' => 'required|in:approve,reject', ...$note],
        }];
    }

    public function mutate(Request $r, string $organization, string $action, ErpWorkflow $workflow): mixed
    {
        $v = $r->validate(self::rules($action));
        foreach (['owner_share_basis_points', 'base_policy_version', 'version', 'output_packs', 'runs', 'opening_value', 'amount', 'good_packs', 'failed_pieces', 'labor_cost', 'overhead_cost', 'stock_revision', 'adjustment_value', 'supplier_credit'] as $key) {
            if (isset($v[$key])) {
                $v[$key] = (int) $v[$key];
            }
        }
        foreach (['amounts', 'recipe_versions', 'allocation_revisions', 'debits', 'credits', 'grant_versions'] as $key) {
            if (isset($v[$key])) {
                foreach ($v[$key] as &$value) {
                    if ($value !== null) {
                        $value = (int) $value;
                    }
                }
            }
        }
        foreach (['consent', 'expiry_required', 'fefo_required', 'active', 'verified', 'enabled'] as $key) {
            if (isset($v[$key])) {
                $v[$key] = (bool) $v[$key];
            }
        }
        try {
            return response()->json(['data' => $workflow->run($r->user(), $organization, $action, $v)], 201)->header('Cache-Control', 'private, no-store');
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }
    }
}
