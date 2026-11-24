<?php

namespace App\Modules\Consumables\Application;

use App\Models\User;
use App\Modules\Consumables\Infrastructure\ConsumableEntry;
use App\Modules\Consumables\Infrastructure\ConsumableItem;
use App\Modules\Consumables\Infrastructure\ConsumableShipment;
use App\Modules\Consumables\Infrastructure\ConsumableShipmentLine;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Shifts\Application\PosAccess;
use App\Shared\Application\Operations;

/**
 * Center-to-booth shipments of packaging and cutlery. Quantity only: no price, invoice or accounting.
 * The center cannot ship more than its recorded balance. The booth confirms what actually arrived and
 * maps each line to its own item, because each organization keeps a separate catalogue.
 */
final class ConsumableShipments
{
    public const SENDERS = ['owner', 'admin', 'warehouse'];

    public function __construct(private Access $access, private PosAccess $pos, private Operations $ops) {}

    private function balance(string $store, string $item): int
    {
        return (int) ConsumableEntry::where('store_id', $store)->where('consumable_item_id', $item)->sum('delta');
    }

    private function view(ConsumableShipment $s, string $viewerStore, array $names): array
    {
        $sender = $s->source_store_id === $viewerStore;
        $row = $s->only(['id', 'status', 'note', 'version', 'dispatched_at', 'received_at', 'receive_note']);
        $row['source_store_id'] = $s->source_store_id;
        $row['destination_store_id'] = $s->destination_store_id;
        $row['source_store_name'] = $names[$s->source_store_id] ?? null;
        $row['destination_store_name'] = $names[$s->destination_store_id] ?? null;
        $row['direction'] = $sender ? 'outgoing' : 'incoming';
        $row['lines'] = $s->lines->map(function ($l) use ($sender) {
            $line = ['id' => $l->id, 'name' => $l->name, 'unit' => $l->unit, 'quantity_sent' => $l->quantity_sent, 'quantity_received' => $l->quantity_received];
            // Each side sees only its own item ids: the center never learns the booth's catalogue and vice versa.
            $sender ? $line['source_item_id'] = $l->source_item_id : $line['destination_item_id'] = $l->destination_item_id;

            return $line;
        })->all();

        return $row;
    }

    public function index(User $u, string $store): array
    {
        $s = $this->access->store($u, $store);
        $isCenter = Organization::findOrFail($s->organization_id)->type === 'center';
        $rows = ConsumableShipment::with('lines')->where(fn ($q) => $q->where('source_store_id', $store)->orWhere('destination_store_id', $store))->orderByDesc('id')->limit(50)->get();
        $names = Store::whereIn('id', $rows->pluck('source_store_id')->merge($rows->pluck('destination_store_id'))->unique())->pluck('name', 'id')->all();
        $destinations = [];
        if ($isCenter) {
            $destinations = Store::join('organizations', 'organizations.id', '=', 'stores.organization_id')
                ->where('organizations.active', true)
                ->where('stores.active', true)
                ->where('stores.id', '!=', $store)
                ->where(fn ($q) => $q->where('organizations.type', 'partner')->orWhere('organizations.id', $s->organization_id))
                ->orderBy('organizations.name')
                ->orderBy('stores.name')
                ->get(['stores.id', 'stores.name', 'organizations.name as organization_name'])
                ->toArray();
        }
        $canReceiveHere = $this->canReceive($u, $s) && (! $isCenter || $rows->contains(fn ($r) => $r->destination_store_id === $store));

        return ['store_id' => $store, 'organization_id' => $s->organization_id, 'is_center' => $isCenter, 'can_send' => $isCenter && $this->canSend($u, $s), 'can_receive' => $canReceiveHere, 'destinations' => $destinations, 'data' => $rows->map(fn ($r) => $this->view($r, $store, $names))->values()->all()];
    }

    private function canSend(User $u, Store $s): bool
    {
        return in_array($this->access->role($u, $s->organization_id)?->value, self::SENDERS, true);
    }

    private function canReceive(User $u, Store $s): bool
    {
        return in_array($this->access->role($u, $s->organization_id)?->value, PosAccess::MANAGERS, true);
    }

    public function dispatch(User $u, string $store, array $v): array
    {
        $source = $this->access->operatingStore($u, $store, self::SENDERS);
        abort_unless(Organization::findOrFail($source->organization_id)->type === 'center', 403, 'Hanya gudang pusat yang dapat mengirim barang non-makanan.');

        return $this->ops->run($source->organization_id, $u->id, $v['operation_id'], 'consumable.dispatch:'.$store, $v, function () use ($u, $source, $v) {
            $destination = Store::where('active', true)->where('id', '!=', $source->id)->find($v['destination_store_id']);
            $targetOrg = $destination ? Organization::where('active', true)->where(fn ($q) => $q->where('type', 'partner')->orWhere('id', $source->organization_id))->find($destination->organization_id) : null;
            abort_unless($destination && $targetOrg, 422, 'Toko tujuan tidak tersedia.');
            $ids = array_column($v['lines'], 'consumable_item_id');
            abort_unless(count($ids) === count(array_unique($ids)), 422, 'Barang tidak boleh dobel dalam satu kiriman.');
            $items = ConsumableItem::where('organization_id', $source->organization_id)->where('active', true)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($items->count() === count($ids), 422, 'Barang tidak tersedia atau tidak aktif.');
            foreach ($v['lines'] as $line) {
                $have = $this->balance($source->id, $line['consumable_item_id']);
                abort_if($have < (int) $line['quantity'], 409, 'Stok gudang kurang untuk '.$items[$line['consumable_item_id']]->name.' (tersedia '.$have.').');
            }
            $shipment = ConsumableShipment::create(['organization_id' => $source->organization_id, 'source_store_id' => $source->id, 'destination_organization_id' => $targetOrg->id, 'destination_store_id' => $destination->id, 'status' => 'dispatched', 'note' => $v['note'] ?? null, 'version' => 1, 'dispatched_by' => $u->id, 'dispatched_at' => now()]);
            foreach ($v['lines'] as $line) {
                $item = $items[$line['consumable_item_id']];
                $row = ConsumableShipmentLine::create(['shipment_id' => $shipment->id, 'source_item_id' => $item->id, 'name' => $item->name, 'unit' => $item->unit, 'quantity_sent' => (int) $line['quantity']]);
                ConsumableEntry::create(['organization_id' => $source->organization_id, 'store_id' => $source->id, 'consumable_item_id' => $item->id, 'delta' => -(int) $line['quantity'], 'reason_type' => 'shipment_out', 'source_type' => 'shipment_dispatch', 'source_id' => $row->id, 'actor_id' => $u->id, 'note' => $v['note'] ?? null, 'created_at' => now()]);
            }
            Audit::record($u->id, $source->organization_id, 'consumable.shipment_dispatched', $shipment->id, null, ['destination_store_id' => $destination->id, 'lines' => count($v['lines'])]);

            return $this->view($shipment->load('lines'), $source->id, [$source->id => $source->name, $destination->id => $destination->name]);
        });
    }

    public function receive(User $u, string $id, array $v): array
    {
        $initial = ConsumableShipment::findOrFail($id);
        $dest = $this->pos->manager($u, $initial->destination_store_id);

        return $this->ops->run($dest->organization_id, $u->id, $v['operation_id'], 'consumable.receive:'.$id, $v, function () use ($u, $id, $dest, $v) {
            $shipment = ConsumableShipment::lockForUpdate()->findOrFail($id);
            abort_unless($shipment->status === 'dispatched', 409, 'Kiriman sudah diterima.');
            abort_unless($shipment->version === (int) $v['version'], 409, 'Versi kiriman berubah. Muat ulang.');
            $lines = $shipment->lines->keyBy('id');
            $given = collect($v['lines'])->keyBy('line_id');
            abort_unless($given->count() === count($v['lines']) && $given->keys()->sort()->values()->all() === $lines->keys()->sort()->values()->all(), 422, 'Setiap baris kiriman harus dikonfirmasi tepat sekali.');
            $short = false;
            $mapped = [];
            foreach ($given as $lineId => $row) {
                $line = $lines[$lineId];
                $qty = (int) $row['quantity_received'];
                abort_if($qty > $line->quantity_sent, 422, 'Jumlah diterima melebihi yang dikirim untuk '.$line->name.'.');
                $item = ConsumableItem::where('organization_id', $dest->organization_id)->where('active', true)->find($row['destination_item_id']);
                abort_unless($item, 422, 'Barang tujuan tidak tersedia atau tidak aktif.');
                $short = $short || $qty < $line->quantity_sent;
                $mapped[$lineId] = [$item, $qty];
            }
            abort_if($short && trim((string) ($v['note'] ?? '')) === '', 422, 'Alasan selisih wajib diisi bila jumlah diterima kurang.');
            foreach ($mapped as $lineId => [$item, $qty]) {
                $lines[$lineId]->fill(['destination_item_id' => $item->id, 'quantity_received' => $qty])->save();
                if ($qty > 0) {
                    ConsumableEntry::create(['organization_id' => $dest->organization_id, 'store_id' => $dest->id, 'consumable_item_id' => $item->id, 'delta' => $qty, 'reason_type' => 'shipment_in', 'source_type' => 'shipment_receipt', 'source_id' => $lineId, 'actor_id' => $u->id, 'note' => $v['note'] ?? null, 'created_at' => now()]);
                }
            }
            $shipment->fill(['status' => 'received', 'received_by' => $u->id, 'received_at' => now(), 'receive_note' => $v['note'] ?? null, 'version' => $shipment->version + 1])->save();
            Audit::record($u->id, $dest->organization_id, 'consumable.shipment_received', $shipment->id, null, ['short' => $short]);
            $names = Store::whereIn('id', [$shipment->source_store_id, $shipment->destination_store_id])->pluck('name', 'id')->all();

            return $this->view($shipment->load('lines'), $dest->id, $names);
        });
    }
}
