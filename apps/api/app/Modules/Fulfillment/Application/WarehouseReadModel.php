<?php

namespace App\Modules\Fulfillment\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Ordering\Application\OrderAccess;
use App\Modules\Ordering\Domain\OrderState;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use Illuminate\Support\Facades\DB;

final class WarehouseReadModel
{
    public function __construct(private Access $identity, private OrderAccess $access, private FulfillmentWorkflow $workflow) {}

    private function source(User $user, string $id): Store
    {
        $store = $this->identity->store($user, $id, ['owner', 'admin', 'warehouse']);
        abort_unless(Organization::whereKey($store->organization_id)->where('type', 'center')->exists(), 403);

        return $store;
    }

    public function inbox(User $user, string $id): array
    {
        $source = $this->source($user, $id);
        $admin = in_array($this->identity->role($user, $source->organization_id)?->value, ['owner', 'admin'], true);
        $query = $this->access->inbox($user)->where('supplier_organization_id', $source->organization_id)->where('store_id', '!=', $id)
            ->where(function ($q) use ($id, $admin) {
                $q->where('source_store_id', $id);
                if ($admin) {
                    $q->orWhere(fn ($open) => $open->whereNull('source_store_id')->where('status', 'approved'));
                }
            });

        return [...$query->with('lines')->orderByDesc('id')->paginate(50)->toArray(), 'source' => $source->only(['id', 'name', 'active']), 'observed_at' => now()->toIso8601String()];
    }

    public function context(User $user, string $sourceId, string $id): array
    {
        $source = $this->source($user, $sourceId);

        return DB::transaction(function () use ($user, $source, $sourceId, $id) {
            $order = SupplyOrder::with('lines')->lockForUpdate()->findOrFail($id);
            $this->access->supplier($user, $order);
            abort_unless($order->supplier_organization_id === $source->organization_id && $order->store_id !== $sourceId, 403);
            abort_unless($order->source_store_id === null || $order->source_store_id === $sourceId, 403, 'Pesanan ditugaskan ke gudang lain.');
            $targetActive = Store::whereKey($order->store_id)->where('active', true)->exists() && Organization::whereKey($order->organization_id)->where('active', true)->exists();
            $hold = $this->access->held($id);
            $active = $source->active && $targetActive && ! $hold;
            $state = OrderState::from($order->status);
            $admin = in_array($this->identity->role($user, $source->organization_id)?->value, ['owner', 'admin'], true);
            $sent = $this->workflow->totals($id, 'quantity_packs');
            $lines = $order->lines->map(fn ($line) => [...$line->toArray(), 'sent_packs' => (int) ($sent[$line->id] ?? 0), 'remaining_packs' => max(0, $line->quantity_packs - (int) ($sent[$line->id] ?? 0))]);
            $canDispatch = $active && $state->allows('dispatch') && $order->source_store_id === $sourceId && $lines->contains(fn ($line) => $line['remaining_packs'] > 0);
            $batches = collect();
            if ($canDispatch) {
                $batches = InventoryBatch::where('store_id', $sourceId)->whereIn('product_id', $order->lines->pluck('supplier_product_id'))->where('usable_pieces', '>', 0)->orderBy('id')->get()
                    ->filter(fn ($batch) => $order->lines->contains(fn ($line) => $line->supplier_product_id === $batch->product_id && $line->pack_size === $batch->pack_size))
                    ->map(fn ($batch) => $batch->only(['id', 'product_id', 'batch_code', 'expires_on', 'pack_size', 'usable_pieces', 'stock_revision']))->values();
            }

            return ['change_pending' => $hold, 'order' => [...$order->toArray(), 'lines' => $lines->all()], 'source' => $source->only(['id', 'name', 'active']), 'can_process' => $active && $admin && $state->allows('process'), 'can_dispatch' => $canDispatch, 'batches' => $batches->all(), 'shipments' => $this->workflow->shipments($id)->toArray(), 'observed_at' => now()->toIso8601String()];
        });
    }
}
