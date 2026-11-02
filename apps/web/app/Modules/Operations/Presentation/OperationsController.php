<?php

namespace App\Modules\Operations\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Request;

final class OperationsController
{
    public function __construct(private Backend $api) {}

    public function store(string $store): mixed
    {
        $context = $this->api->request('GET', "stores/$store/operations-context")->json('data');

        return view('pages.operations.store', ['store' => $context, 'orders' => $context['can_order'] ? $this->api->page("stores/$store/orders", 'orders_page') : null, 'batches' => $this->api->page("stores/$store/inventory/batches", 'batches_page'), 'products' => $context['can_intake'] ? $this->api->all("stores/$store/catalog") : []]);
    }

    public function createForm(string $store): mixed
    {
        return view('pages.operations.order-form', ['store' => $this->api->request('GET', "stores/$store/operations-context")->json('data'), 'supply' => $this->api->all("stores/$store/supply-catalog"), 'local' => $this->api->all("stores/$store/catalog"), 'order' => null]);
    }

    public function editForm(string $order): mixed
    {
        $record = $this->api->request('GET', "orders/$order")->json('data');

        return view('pages.operations.order-form', ['store' => $this->api->request('GET', "stores/{$record['store_id']}/operations-context")->json('data'), 'supply' => $this->api->all("stores/{$record['store_id']}/supply-catalog"), 'local' => $this->api->all("stores/{$record['store_id']}/catalog"), 'order' => $record]);
    }

    public function create(Request $r, string $store): mixed
    {
        $result = $this->api->request('POST', "stores/$store/orders", $r->only('operation_id', 'delivery_mode', 'note', 'lines'))->json('data');

        return redirect('/app/orders/'.$result['id'])->with('status', 'Pengajuan dibuat. Pusat baru dapat memproses setelah disetujui.');
    }

    public function amend(Request $r, string $order): mixed
    {
        $this->api->request('PUT', "orders/$order", $r->only('operation_id', 'version', 'delivery_mode', 'note', 'lines'));

        return redirect('/app/orders/'.$order)->with('status', 'Pengajuan diperbarui. Tinjau sebelum menyetujui.');
    }

    public function inbox(): mixed
    {
        return view('pages.operations.inbox', ['orders' => $this->api->page('supply/orders')]);
    }

    public function show(string $order): mixed
    {
        $record = $this->api->request('GET', "orders/$order")->json('data');
        $batches = [];
        if (! ($record['change_pending'] ?? false) && $record['source_store_id'] && $record['can_process']) {
            $batches = $this->api->all("stores/{$record['source_store_id']}/inventory/batches");
        } elseif (! ($record['change_pending'] ?? false) && $record['source_store_id'] && $record['access_side'] === 'supplier') {
            $batches = $this->api->all("stores/{$record['source_store_id']}/inventory/batches");
        }

        $shipments = $this->api->request('GET', "orders/$order/shipments")->json('data');
        $progress = [];
        foreach ($shipments as $shipment) {
            foreach ($shipment['lines'] as $line) {
                $key = $line['supply_order_line_id'] ?? null;
                if ($key === null) {
                    continue;
                }
                $progress[$key]['dispatched'] = ($progress[$key]['dispatched'] ?? 0) + $line['quantity_packs'];
                $progress[$key]['good'] = ($progress[$key]['good'] ?? 0) + ($line['good_packs'] ?? 0);
            }
        }

        return view('pages.operations.order', ['order' => $record, 'shipments' => $shipments, 'progress' => $progress, 'stores' => $record['can_process'] ? $this->api->all('stores') : [], 'batches' => $batches]);
    }

    public function transition(Request $r, string $order, string $action): mixed
    {
        abort_unless(in_array($action, ['approve', 'reject', 'cancel', 'process'], true), 404);
        $this->api->request('POST', "orders/$order/$action", $r->only('operation_id', 'version', 'source_store_id', 'reason'));

        return redirect('/app/orders/'.$order)->with('status', 'Status pesanan diperbarui.');
    }

    public function intake(Request $r, string $store): mixed
    {
        $this->api->request('POST', "stores/$store/inventory/intakes", $r->only('operation_id', 'product_id', 'batch_code', 'expires_on', 'quantity_packs', 'note'));

        return back()->with('status', 'Penerimaan gudang dicatat pada ledger.');
    }

    public function dispatch(Request $r, string $order): mixed
    {
        // Unselected rows are omitted; all selected allocations remain validated by the API.
        $data = $r->only('operation_id', 'version', 'handler', 'note');
        $data['lines'] = array_values(array_filter($r->input('lines', []), fn ($line) => collect($line['allocations'] ?? [])->contains(fn ($a) => (int) ($a['quantity_packs'] ?? 0) > 0)));
        foreach ($data['lines'] as &$line) {
            $line['allocations'] = array_values(array_filter($line['allocations'], fn ($a) => (int) ($a['quantity_packs'] ?? 0) > 0));
        }unset($line);
        $this->api->request('POST', "orders/$order/shipments", $data);

        return redirect('/app/orders/'.$order)->with('status', 'Pengiriman dicatat dan stok gudang berkurang sekali.');
    }

    public function receive(Request $r, string $order, string $shipment): mixed
    {
        $this->api->request('POST', "shipments/$shipment/receive", $r->only('operation_id', 'version', 'note', 'lines'));

        return redirect('/app/orders/'.$order)->with('status', 'Penerimaan aktual dicatat. Periksa stok baik, karantina dan sisa pesanan.');
    }
}
