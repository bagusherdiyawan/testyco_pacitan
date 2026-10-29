<?php

namespace App\Modules\Ordering\Application;

use App\Models\User;
use App\Modules\Catalog\Application\SupplyCatalog;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Ordering\Domain\OrderState;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;

final class OrderWorkflow
{
    public function __construct(private OrderAccess $orders, private Access $access, private SupplyCatalog $catalog, private Operations $operations) {}

    private function snapshots(string $organization, array $items): array
    {
        $internal = Organization::findOrFail($organization)->type === 'center';
        $lines = [];
        $suppliers = [];
        foreach ($items as $item) {
            $line = $this->catalog->line($item['supplier_product_id'], $item['local_product_id'], $organization, (int) $item['quantity_packs'], $internal);
            $suppliers[] = $line['supplier_organization_id'];
            unset($line['supplier_organization_id']);
            $lines[] = $line;
        }
        $suppliers = array_unique($suppliers);
        abort_unless(count($suppliers) === 1, 422, 'Semua item harus dari pemasok pusat yang sama.');
        $supplier = reset($suppliers);
        abort_if($internal && $supplier !== $organization, 422, 'Transfer internal harus dalam organisasi pusat sendiri.');

        return ['kind' => $internal ? 'internal_transfer' : 'wholesale', 'supplier_organization_id' => $supplier, 'total_amount' => array_sum(array_map(fn ($l) => $l['quantity_packs'] * $l['unit_price'], $lines)), 'lines' => $lines];
    }

    private function review(Store $store, array $v, array $snapshot): array
    {
        return ['store_id' => $store->id, 'destination_name' => $store->name, 'destination_address' => $store->address, 'delivery_mode' => $v['delivery_mode'], 'note' => $v['note'] ?? null, ...$snapshot];
    }

    private function reviewHash(array $review): string
    {
        return hash('sha256', json_encode($review, JSON_THROW_ON_ERROR));
    }

    public function preview(User $user, string $store, array $v): array
    {
        $s = $this->orders->recipient($user, $store, true);

        return DB::transaction(function () use ($s, $v) {
            $s = Store::whereKey($s->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->active, 409, 'Toko tujuan nonaktif.');
            abort_if($v['delivery_mode'] === 'delivery' && ! trim((string) $s->address), 422, 'Alamat toko wajib diisi untuk pengiriman.');
            $snapshot = $this->review($s, $v, $this->snapshots($s->organization_id, $v['lines']));

            return [...$snapshot, 'review_hash' => $this->reviewHash($snapshot), 'currency' => 'IDR', 'observed_at' => now()->toIso8601String()];
        });
    }

    private function amendReview(Store $store, SupplyOrder $order, array $v, array $snapshot): array
    {
        return ['order_id' => $order->id, 'version' => $order->version, ...$this->review($store, $v, $snapshot)];
    }

    public function previewAmend(User $user, string $id, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $this->orders->approver($user, $initial);

        return DB::transaction(function () use ($user, $id, $v) {
            $order = $this->lock($id);
            $this->requireState($order, 'amend', (int) $v['version']);
            $destination = $this->orders->recipient($user, $order->store_id, true);
            $destination = Store::whereKey($destination->id)->lockForUpdate()->firstOrFail();
            abort_unless($destination->active, 409, 'Toko tujuan nonaktif.');
            abort_if($v['delivery_mode'] === 'delivery' && ! trim((string) $destination->address), 422, 'Alamat toko wajib diisi untuk pengiriman.');
            $snapshot = $this->amendReview($destination, $order, $v, $this->snapshots($order->organization_id, $v['lines']));

            return [...$snapshot, 'review_hash' => $this->reviewHash($snapshot), 'currency' => 'IDR', 'observed_at' => now()->toIso8601String()];
        });
    }

    public function create(User $user, string $store, array $v): array
    {
        $s = $this->orders->recipient($user, $store, true);

        return $this->operations->run($s->organization_id, $user->id, $v['operation_id'], 'order.create:'.$store, $v, function () use ($user, $s, $v) {
            $s = Store::whereKey($s->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->active, 409, 'Toko tujuan nonaktif.');
            abort_if($v['delivery_mode'] === 'delivery' && ! trim((string) $s->address), 422, 'Alamat toko wajib diisi untuk pengiriman.');
            $snap = $this->snapshots($s->organization_id, $v['lines']);
            if (isset($v['review_hash'])) {
                abort_unless(hash_equals($this->reviewHash($this->review($s, $v, $snap)), $v['review_hash']), 409, 'Data pengajuan berubah setelah ditinjau. Muat ulang dan tinjau kembali sebelum mengirim.');
            }
            $lines = $snap['lines'];
            unset($snap['lines']);
            $order = SupplyOrder::create([...$snap, 'organization_id' => $s->organization_id, 'store_id' => $s->id, 'destination_name' => $s->name, 'destination_address' => $s->address, 'created_by' => $user->id, 'status' => OrderState::Awaiting->value, 'version' => 1, 'delivery_mode' => $v['delivery_mode'], 'note' => $v['note'] ?? null]);
            foreach ($lines as $line) {
                $order->lines()->create($line);
            }
            Audit::record($user->id, $s->organization_id, 'order.created', $order->id, null, $order->load('lines')->toArray());

            return $order->toArray();
        });
    }

    public function lock(string $id): SupplyOrder
    {
        return SupplyOrder::lockForUpdate()->with('lines')->findOrFail($id);
    }

    public function requireState(SupplyOrder $order, string $action, int $version): void
    {
        abort_unless($order->version === $version, 409, 'Versi pesanan berubah. Muat ulang sebelum melanjutkan.');
        abort_unless(OrderState::from($order->status)->allows($action), 409, 'Status pesanan tidak mengizinkan tindakan ini.');
    }

    public function transition(User $user, string $id, string $action, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        if ($action === 'process') {
            $this->orders->supplier($user, $initial);
            abort_unless(in_array($this->access->role($user, $initial->supplier_organization_id)?->value, ['owner', 'admin'], true), 403);
            $org = $initial->supplier_organization_id;
        } else {
            $this->orders->approver($user, $initial);
            $org = $initial->organization_id;
        }

        return $this->operations->run($org, $user->id, $v['operation_id'], 'order.'.$action.':'.$id, $v, function () use ($user, $id, $action, $v) {
            $order = $this->lock($id);
            $this->requireState($order, $action, (int) $v['version']);
            $before = $order->toArray();
            if ($action === 'approve') {
                foreach ($order->lines as $line) {
                    $fresh = $this->catalog->line($line->supplier_product_id, $line->local_product_id, $order->organization_id, $line->quantity_packs, $order->kind === 'internal_transfer');
                    abort_unless($fresh['unit_price'] === $line->unit_price && $fresh['pack_size'] === $line->pack_size && $fresh['condition'] === $line->condition, 409, 'Harga atau kemasan berubah. Perbarui pengajuan dan tinjau ulang.');
                }
                $order->fill(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => null]);
            } elseif ($action === 'reject') {
                $order->fill(['status' => 'rejected', 'decision_note' => $v['reason']]);
            } elseif ($action === 'cancel') {
                $order->fill(['status' => 'cancelled', 'decision_note' => $v['reason']]);
            } elseif ($action === 'process') {
                $this->orders->targetActive($order);
                $this->orders->source($user, $order, $v['source_store_id']);
                $order->fill(['status' => 'processing', 'source_store_id' => $v['source_store_id']]);
            }
            $order->version++;
            $order->save();
            Audit::record($user->id, $order->organization_id, 'order.'.$action, $id, $before, $order->toArray());

            return $order->toArray();
        });
    }

    public function amend(User $user, string $id, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $this->orders->approver($user, $initial);

        return $this->operations->run($initial->organization_id, $user->id, $v['operation_id'], 'order.amend:'.$id, $v, function () use ($user, $id, $v) {
            $order = $this->lock($id);
            $this->requireState($order, 'amend', (int) $v['version']);
            $before = $order->toArray();
            $destination = $this->orders->recipient($user, $order->store_id, true);
            $destination = Store::whereKey($destination->id)->lockForUpdate()->firstOrFail();
            abort_unless($destination->active, 409, 'Toko tujuan nonaktif.');
            abort_if($v['delivery_mode'] === 'delivery' && ! trim((string) $destination->address), 422, 'Alamat toko wajib diisi untuk pengiriman.');
            $snap = $this->snapshots($order->organization_id, $v['lines']);
            if (isset($v['review_hash'])) {
                abort_unless(hash_equals($this->reviewHash($this->amendReview($destination, $order, $v, $snap)), $v['review_hash']), 409, 'Data perubahan berbeda dari tinjauan. Muat ulang dan tinjau kembali.');
            }
            $lines = $snap['lines'];
            unset($snap['lines']);
            $order->lines()->delete();
            foreach ($lines as $line) {
                $order->lines()->create($line);
            }
            $order->fill([...$snap, 'destination_name' => $destination->name, 'destination_address' => $destination->address, 'status' => 'awaiting_approval', 'delivery_mode' => $v['delivery_mode'], 'note' => $v['note'] ?? null, 'decision_note' => null, 'version' => $order->version + 1]);
            $order->save();
            $order->load('lines');
            Audit::record($user->id, $order->organization_id, 'order.amended', $id, $before, $order->toArray());

            return $order->toArray();
        });
    }

    public function fulfillmentState(User $user, SupplyOrder $order, string $status): void
    {
        $before = ['status' => $order->status, 'version' => $order->version];
        $order->fill(['status' => $status, 'version' => $order->version + 1]);
        $order->save();
        Audit::record($user->id, $order->organization_id, 'order.fulfillment_updated', $order->id, $before, ['status' => $status, 'version' => $order->version]);
    }
}
