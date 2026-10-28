<?php

namespace App\Modules\Fulfillment\Application;

use App\Models\User;
use App\Modules\Fulfillment\Infrastructure\Shipment;
use App\Modules\Fulfillment\Infrastructure\ShipmentLine;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Ordering\Application\OrderAccess;
use App\Modules\Ordering\Application\OrderWorkflow;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FulfillmentWorkflow
{
    public function __construct(private OrderWorkflow $book, private OrderAccess $access, private StockLedger $stock, private Operations $operations) {}

    public function shipments(string $order)
    {
        return Shipment::where('supply_order_id', $order)->with('lines')->orderBy('id')->get();
    }

    public function totals(string $order, string $field): array
    {
        abort_unless(in_array($field, ['quantity_packs', 'good_packs'], true), 422);

        return DB::table('shipment_lines')->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')->where('supply_order_id', $order)->groupBy('supply_order_line_id')->selectRaw('supply_order_line_id, SUM('.$field.') AS quantity')->pluck('quantity', 'supply_order_line_id')->all();
    }

    public function dispatch(User $user, string $id, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $this->access->supplier($user, $initial);

        return $this->operations->run($initial->supplier_organization_id, $user->id, $v['operation_id'], 'shipment.dispatch:'.$id, $v, function () use ($user, $id, $v) {
            $order = $this->book->lock($id);
            $this->book->requireState($order, 'dispatch', (int) $v['version']);
            $this->access->source($user, $order, $order->source_store_id);
            $target = Store::findOrFail($order->store_id);
            abort_unless($target->active && Organization::findOrFail($order->organization_id)->active, 409, 'Toko atau organisasi tujuan nonaktif.');
            $sent = $this->totals($id, 'quantity_packs');
            $lines = $order->lines->keyBy('id');
            $work = [];
            foreach ($v['lines'] as $requested) {
                $line = $lines->get($requested['order_line_id']);
                abort_unless($line, 422, 'Item tidak termasuk pesanan ini.');
                $total = array_sum(array_column($requested['allocations'], 'quantity_packs'));
                abort_unless($total <= ($line->quantity_packs - ($sent[$line->id] ?? 0)), 422, 'Jumlah kirim melebihi sisa pesanan.');
                foreach ($requested['allocations'] as $allocation) {
                    $work[] = ['line' => $line, 'batch_id' => $allocation['batch_id'], 'packs' => (int) $allocation['quantity_packs']];
                }
            }
            usort($work, fn ($a, $b) => strcmp($a['batch_id'], $b['batch_id']));
            $shipment = Shipment::create(['supply_order_id' => $id, 'dispatched_by' => $user->id, 'handler' => $v['handler'], 'note' => $v['note'] ?? null, 'dispatched_at' => now()]);
            foreach ($work as $item) {
                $line = $item['line'];
                $shipmentLineId = (string) Str::ulid();
                $batch = $this->stock->dispatch($item['batch_id'], $order->source_store_id, $line->supplier_product_id, $line->pack_size, $item['packs'], $user->id, $shipmentLineId);
                $shipmentLine = new ShipmentLine(['shipment_id' => $shipment->id, 'supply_order_line_id' => $line->id, 'inventory_batch_id' => $item['batch_id'], 'batch_code' => $batch['batch_code'], 'expires_on' => $batch['expires_on'], 'pack_size' => $line->pack_size, 'quantity_packs' => $item['packs']]);
                $shipmentLine->id = $shipmentLineId;
                $shipmentLine->save();
            }
            $hadReceipts = Shipment::where('supply_order_id', $id)->whereNotNull('received_at')->exists();
            $this->book->fulfillmentState($user, $order, $hadReceipts ? 'partially_received' : 'dispatched');
            $shipment->load('lines');
            Audit::record($user->id, $order->supplier_organization_id, 'shipment.dispatched', $shipment->id, null, $shipment->toArray());

            return ['order' => $order->toArray(), 'shipment' => $shipment->toArray()];
        });
    }

    public function receive(User $user, string $shipmentId, array $v): array
    {
        $initial = Shipment::findOrFail($shipmentId);
        $initialOrder = SupplyOrder::findOrFail($initial->supply_order_id);
        $this->access->recipient($user, $initialOrder->store_id, true);

        return $this->operations->run($initialOrder->organization_id, $user->id, $v['operation_id'], 'shipment.receive:'.$shipmentId, $v, function () use ($user, $initial, $shipmentId, $v) {
            $order = $this->book->lock($initial->supply_order_id);
            $this->book->requireState($order, 'receive', (int) $v['version']);
            $shipment = Shipment::where('supply_order_id', $order->id)->lockForUpdate()->with('lines')->findOrFail($shipmentId);
            abort_if($shipment->received_at, 409, 'Pengiriman sudah dikonfirmasi.');
            $received = collect($v['lines'])->keyBy('shipment_line_id');
            abort_unless(count($v['lines']) === $shipment->lines->count() && $received->count() === $shipment->lines->count(), 422, 'Konfirmasikan setiap baris pengiriman tepat sekali.');
            foreach ($shipment->lines as $line) {
                $actual = $received->get($line->id);
                abort_unless($actual, 422, 'Baris penerimaan tidak sesuai pengiriman.');
                $good = (int) $actual['good_packs'];
                $damaged = (int) $actual['damaged_packs'];
                abort_if($good + $damaged > $line->quantity_packs, 422, 'Jumlah diterima melebihi jumlah dikirim.');
                if ($good !== $line->quantity_packs) {
                    abort_unless(trim((string) ($actual['condition_note'] ?? '')) !== '', 422, 'Selisih atau kerusakan wajib disertai catatan.');
                }
                $item = $order->lines->firstWhere('id', $line->supply_order_line_id);
                $product = ['id' => $item->local_product_id, 'sku' => $item->local_sku, 'name' => $item->local_name, 'condition' => $item->condition, 'pack_size' => $line->pack_size];
                foreach (['usable' => $good, 'quarantined' => $damaged] as $bucket => $packs) {
                    if ($packs > 0) {
                        $this->stock->credit($order->organization_id, $order->store_id, $product, $line->batch_code, $line->expires_on->format('Y-m-d'), $packs, $user->id, 'shipment_receipt', $line->id, $bucket, $actual['condition_note'] ?? null);
                    }
                }
                $line->fill(['good_packs' => $good, 'damaged_packs' => $damaged, 'condition_note' => $actual['condition_note'] ?? null]);
                $line->save();
            }
            $shipment->fill(['received_by' => $user->id, 'received_at' => now(), 'receipt_note' => $v['note'] ?? null]);
            $shipment->save();
            $totals = $this->totals($order->id, 'good_packs');
            $complete = $order->lines->every(fn ($line) => (int) ($totals[$line->id] ?? 0) === $line->quantity_packs);
            $this->book->fulfillmentState($user, $order, $complete ? 'completed' : 'partially_received');
            $shipment->load('lines');
            Audit::record($user->id, $order->organization_id, 'shipment.received', $shipmentId, null, $shipment->toArray());

            return ['order' => $order->toArray(), 'shipment' => $shipment->toArray()];
        });
    }
}
