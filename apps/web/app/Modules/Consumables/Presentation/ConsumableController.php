<?php

namespace App\Modules\Consumables\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Request;

/** Thin BFF over the API: kemasan dan alat makan for booths. All authorization stays in the API. */
final class ConsumableController
{
    public const SERVICE_TYPES = ['dine_in' => 'Makan di tempat', 'takeaway' => 'Bawa pulang'];

    public const BASES = ['portion' => 'per porsi terjual', 'transaction' => 'per transaksi'];

    public function __construct(private Backend $api) {}

    public function catalogue(string $organization): mixed
    {
        $c = $this->api->request('GET', "organizations/$organization/consumables")->json('data');
        abort_unless(is_array($c) && ($c['organization_id'] ?? null) === $organization && is_array($c['items'] ?? null) && is_array($c['rules'] ?? null), 503);
        foreach ([...$c['items'], ...$c['rules']] as $row) {
            abort_unless(($row['organization_id'] ?? null) === $organization, 503);
        }

        return response()->view('pages.consumables.catalogue', ['organization' => $organization, 'items' => $c['items'], 'rules' => $c['rules'], 'serviceTypes' => self::SERVICE_TYPES, 'bases' => self::BASES])->header('Cache-Control', 'private, no-store');
    }

    public function createItem(Request $r, string $organization): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'name' => 'required|string|max:120', 'unit' => 'required|string|max:30']);
        $this->api->request('POST', "organizations/$organization/consumables/items", $v);

        return redirect("/app/organizations/$organization/consumables")->with('status', 'Barang ditambahkan.');
    }

    public function itemStatus(Request $r, string $organization, string $item): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'active' => 'required|boolean', 'reason' => 'required|string|max:2000']);
        $v['version'] = (int) $v['version'];
        $v['active'] = (bool) $v['active'];
        $this->api->request('POST', "organizations/$organization/consumables/items/$item/status", $v);

        return redirect("/app/organizations/$organization/consumables")->with('status', 'Status barang diperbarui.');
    }

    public function rule(Request $r, string $organization): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'service_type' => 'required|in:'.implode(',', array_keys(self::SERVICE_TYPES)), 'consumable_item_id' => 'required|ulid', 'basis' => 'required|in:'.implode(',', array_keys(self::BASES)), 'quantity' => 'required|integer|min:0|max:1000']);
        $v['quantity'] = (int) $v['quantity'];
        $this->api->request('POST', "organizations/$organization/consumables/rules", $v);

        return redirect("/app/organizations/$organization/consumables")->with('status', $v['quantity'] === 0 ? 'Aturan dihapus.' : 'Aturan disimpan.');
    }

    public function stock(string $store): mixed
    {
        $c = $this->api->request('GET', "stores/$store/consumables")->json('data');
        abort_unless(is_array($c) && ($c['store_id'] ?? null) === $store && is_array($c['items'] ?? null) && is_array($c['recent'] ?? null), 503);
        $shift = null;
        try {
            $shift = $this->api->request('GET', "stores/$store/pos/context")->json('data.shift');
        } catch (\Throwable) {
            // The cash-out option is simply hidden when no open shift can be read.
        }
        $names = collect($c['items'])->pluck('name', 'id')->all();

        return response()->view('pages.consumables.stock', ['store' => $store, 'items' => $c['items'], 'recent' => $c['recent'], 'names' => $names, 'shift' => is_array($shift) ? $shift : null])->header('Cache-Control', 'private, no-store');
    }

    public function purchase(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'consumable_item_id' => 'required|ulid', 'quantity' => 'required|integer|min:1|max:1000000', 'amount' => 'nullable|integer|min:0|max:1000000000000', 'note' => 'nullable|string|max:400', 'record_cash' => 'nullable|boolean', 'shift_id' => 'nullable|ulid', 'shift_version' => 'nullable|integer|min:1']);
        $body = ['operation_id' => $v['operation_id'], 'consumable_item_id' => $v['consumable_item_id'], 'quantity' => (int) $v['quantity'], 'note' => $v['note'] ?? null];
        if (isset($v['amount']) && $v['amount'] !== '') {
            $body['amount'] = (int) $v['amount'];
        }
        if ($r->boolean('record_cash')) {
            $body['shift_id'] = $v['shift_id'] ?? null;
            $body['shift_version'] = isset($v['shift_version']) ? (int) $v['shift_version'] : null;
        }
        $this->api->request('POST', "stores/$store/consumables/purchases", $body);

        return redirect("/app/stores/$store/consumables")->with('status', $r->boolean('record_cash') ? 'Pembelian dicatat: stok bertambah dan kas keluar tercatat.' : 'Pembelian dicatat: stok bertambah.');
    }

    public function count(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'consumable_item_id' => 'required|ulid', 'counted' => 'required|integer|min:0|max:1000000', 'reason' => 'required|string|max:1000']);
        $v['counted'] = (int) $v['counted'];
        $this->api->request('POST', "stores/$store/consumables/counts", $v);

        return redirect("/app/stores/$store/consumables")->with('status', 'Hasil hitung fisik dicatat.');
    }

    public function shipments(string $store): mixed
    {
        $c = $this->api->request('GET', "stores/$store/consumable-shipments")->json();
        abort_unless(is_array($c) && ($c['store_id'] ?? null) === $store && is_array($c['data'] ?? null) && is_array($c['destinations'] ?? null), 503);
        foreach ($c['data'] as $row) {
            abort_unless(in_array($store, [$row['source_store_id'] ?? null, $row['destination_store_id'] ?? null], true), 503);
        }
        $stock = $this->api->request('GET', "stores/$store/consumables")->json('data');
        abort_unless(is_array($stock) && ($stock['store_id'] ?? null) === $store && is_array($stock['items'] ?? null), 503);

        return response()->view('pages.consumables.shipments', ['store' => $store, 'shipments' => $c['data'], 'destinations' => $c['destinations'], 'canSend' => (bool) ($c['can_send'] ?? false), 'canReceive' => (bool) ($c['can_receive'] ?? false), 'items' => $stock['items']])->header('Cache-Control', 'private, no-store');
    }

    public function dispatchShipment(Request $r, string $store): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'destination_store_id' => 'required|ulid', 'note' => 'nullable|string|max:500', 'lines' => 'required|array|max:50', 'lines.*.consumable_item_id' => 'nullable|ulid', 'lines.*.quantity' => 'nullable|integer|min:1|max:1000000']);
        $lines = [];
        foreach ($v['lines'] as $line) {
            if (! empty($line['consumable_item_id']) && ! empty($line['quantity'])) {
                $lines[] = ['consumable_item_id' => $line['consumable_item_id'], 'quantity' => (int) $line['quantity']];
            }
        }
        if (! $lines) {
            return back()->withInput()->withErrors(['lines' => 'Isi minimal satu barang dan jumlahnya.']);
        }
        $this->api->request('POST', "stores/$store/consumable-shipments", ['operation_id' => $v['operation_id'], 'destination_store_id' => $v['destination_store_id'], 'note' => $v['note'] ?? null, 'lines' => $lines]);

        return redirect("/app/stores/$store/consumable-shipments")->with('status', 'Kiriman dicatat dan stok gudang berkurang.');
    }

    public function receiveShipment(Request $r, string $shipment): mixed
    {
        $v = $r->validate(['operation_id' => 'required|uuid', 'store_id' => 'required|ulid', 'version' => 'required|integer|min:1', 'note' => 'nullable|string|max:1000', 'lines' => 'required|array|min:1|max:50', 'lines.*.line_id' => 'required|ulid', 'lines.*.destination_item_id' => 'required|ulid', 'lines.*.quantity_received' => 'required|integer|min:0|max:1000000']);
        $lines = array_map(fn ($l) => ['line_id' => $l['line_id'], 'destination_item_id' => $l['destination_item_id'], 'quantity_received' => (int) $l['quantity_received']], array_values($v['lines']));
        $this->api->request('POST', "consumable-shipments/$shipment/receive", ['operation_id' => $v['operation_id'], 'version' => (int) $v['version'], 'note' => $v['note'] ?? null, 'lines' => $lines]);

        return redirect('/app/stores/'.$v['store_id'].'/consumable-shipments')->with('status', 'Penerimaan dicatat dan stok booth bertambah.');
    }
}
