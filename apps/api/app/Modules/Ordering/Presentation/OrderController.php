<?php

namespace App\Modules\Ordering\Presentation;

use App\Modules\Catalog\Application\SupplyCatalog;
use App\Modules\Fulfillment\FulfillmentServiceProvider;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Inventory\Application\StockAccess;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Ordering\Application\OrderAccess;
use App\Modules\Ordering\Application\OrderWorkflow;
use App\Modules\Ordering\Application\RecipientOrderActions;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Modules\Sales\SalesServiceProvider;
use App\Modules\Shifts\Application\PosAccess;
use Illuminate\Http\Request;

final class OrderController
{
    public function __construct(private OrderAccess $access, private OrderWorkflow $workflow, private Access $identity, private RecipientOrderActions $recipientActions) {}

    public function context(Request $r, string $store): mixed
    {
        $s = $this->identity->store($r->user(), $store);
        $role = $this->identity->role($r->user(), $s->organization_id)?->value;

        return ['data' => [...$s->toArray(), 'role' => $role, 'can_manage_stock' => app()->getProvider(InventoryServiceProvider::class) && in_array($role, StockAccess::STAFF, true), 'can_review_stock' => app()->getProvider(InventoryServiceProvider::class) && app(StockAccess::class)->canReview($r->user(), $s), 'can_pos' => app()->getProvider(SalesServiceProvider::class) && in_array($role, PosAccess::CASHIERS, true), 'order_kind' => Organization::findOrFail($s->organization_id)->type === 'center' ? 'internal_transfer' : 'wholesale', 'can_order' => in_array($role, OrderAccess::RECIPIENT_ROLES, true), 'can_intake' => in_array($role, ['owner', 'admin', 'warehouse'], true) && Organization::findOrFail($s->organization_id)->type === 'center']];
    }

    public function catalog(Request $r, string $store, SupplyCatalog $catalog): mixed
    {
        $this->access->recipient($r->user(), $store, true);

        return $catalog->available()->orderBy('name')->paginate(100, ['id', 'organization_id', 'sku', 'name', 'condition', 'pack_size', 'wholesale_price']);
    }

    public function index(Request $r, string $store): mixed
    {
        $this->access->recipient($r->user(), $store);

        return SupplyOrder::where('store_id', $store)->with('lines')->orderByDesc('id')->paginate(50);
    }

    public function inbox(Request $r): mixed
    {
        return $this->access->inbox($r->user())->with('lines')->orderByDesc('id')->paginate(50);
    }

    public function show(Request $r, string $order): mixed
    {
        $record = SupplyOrder::with('lines')->findOrFail($order);
        $side = $this->access->view($r->user(), $record);
        $role = $this->identity->role($r->user(), $record->organization_id)?->value;
        $supplierRole = $this->identity->role($r->user(), $record->supplier_organization_id)?->value;

        $fulfillment = app()->getProvider(FulfillmentServiceProvider::class) !== null;

        return ['data' => [...$record->toArray(), 'fulfillment_enabled' => $fulfillment, 'recipient_actions' => $this->recipientActions->for($r->user(), $record, $side, $fulfillment), 'access_side' => $side, 'can_approve' => in_array($role, $record->kind === 'internal_transfer' ? ['owner', 'admin'] : ['partner_lead'], true), 'can_process' => $record->approved_at !== null && in_array($supplierRole, ['owner', 'admin'], true)]];
    }

    private function items(): array
    {
        return ['delivery_mode' => 'required|in:delivery,pickup', 'note' => 'nullable|string|max:2000', 'lines' => 'required|array|min:1|max:100', 'lines.*.supplier_product_id' => 'required|ulid|distinct', 'lines.*.local_product_id' => 'required|ulid|distinct', 'lines.*.quantity_packs' => 'required|integer|min:1|max:10000'];
    }

    public function preview(Request $r, string $store): mixed
    {
        $v = $r->validate($this->items());

        return response()->json(['data' => $this->workflow->preview($r->user(), $store, $v)])->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'review_hash' => 'sometimes|required|string|size:64|regex:/^[a-f0-9]{64}$/', ...$this->items()]);

        return response()->json(['data' => $this->workflow->create($r->user(), $store, $v)], 201);
    }

    public function amend(Request $r, string $order): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', ...$this->items()]);

        return ['data' => $this->workflow->amend($r->user(), $order, $v)];
    }

    public function transition(Request $r, string $order, string $action): mixed
    {
        abort_unless(in_array($action, ['approve', 'reject', 'cancel', 'process'], true), 404);
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => in_array($action, ['reject', 'cancel'], true) ? 'required|string|max:2000' : 'nullable|string|max:2000', 'source_store_id' => $action === 'process' ? 'required|ulid' : 'nullable|ulid']);

        return ['data' => $this->workflow->transition($r->user(), $order, $action, $v)];
    }
}
