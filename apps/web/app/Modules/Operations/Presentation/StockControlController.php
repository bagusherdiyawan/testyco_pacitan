<?php

namespace App\Modules\Operations\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Request;

final class StockControlController
{
    public function __construct(private Backend $api) {}

    public function index(string $store): mixed
    {
        $context = $this->api->request('GET', "stores/$store/operations-context")->json('data');
        abort_unless(($context['can_manage_stock'] ?? false) || ($context['can_process_stock'] ?? false), 403);
        $canManage = (bool) ($context['can_manage_stock'] ?? false);

        return view('pages.operations.stock-control', ['store' => $context, 'canPropose' => $canManage, 'batches' => $this->api->all("stores/$store/inventory/batches"), 'processes' => $this->api->page("stores/$store/inventory/processes", 'processes_page'), 'counts' => $canManage ? $this->api->page("stores/$store/inventory/counts", 'counts_page') : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 50)]);
    }

    public function process(Request $r, string $store): mixed
    {
        $this->api->request('POST', "stores/$store/inventory/processes", $r->only('operation_id', 'batch_id', 'expected_revision', 'kind', 'quantity_packs', 'input_pieces', 'output_pieces', 'reason'));

        return redirect('/app/stores/'.$store.'/stock-control')->with('status', 'Pengolahan dan hasil aktual dicatat sekali pada ledger.');
    }

    public function propose(Request $r, string $store): mixed
    {
        $this->api->request('POST', "stores/$store/inventory/counts", $r->only('operation_id', 'batch_id', 'expected_revision', 'purpose', 'bucket', 'counted_pieces', 'reason'));

        return redirect('/app/stores/'.$store.'/stock-control')->with('status', 'Pengajuan tersimpan. Saldo stok belum berubah; menunggu persetujuan.');
    }

    public function review(Request $r, string $store, string $count, string $action): mixed
    {
        abort_unless(in_array($action, ['approve', 'reject', 'cancel'], true), 404);
        $this->api->request('POST', "inventory/counts/$count/$action", $r->only('operation_id', 'version', 'review_note'));

        return redirect('/app/stores/'.$store.'/stock-control')->with('status', 'Tinjauan pengajuan stok dicatat.');
    }
}
