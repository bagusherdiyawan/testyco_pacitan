<?php

namespace App\Modules\Operations\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class StockLedgerController
{
    public const SOURCES = ['warehouse_intake' => 'Penerimaan awal gudang', 'shipment_dispatch' => 'Pengiriman', 'shipment_receipt' => 'Penerimaan pesanan', 'inventory_open' => 'Buka kemasan', 'inventory_cook' => 'Pengukusan', 'stock_count' => 'Stok opname', 'stock_waste' => 'Susut disetujui', 'pos_sale' => 'Penjualan POS'];

    public const BUCKETS = ['usable' => 'Utuh', 'opened' => 'Beku terbuka', 'ready' => 'Siap jual', 'quarantined' => 'Karantina', 'waste' => 'Susut'];

    public function __construct(private Backend $api) {}

    public function index(Request $r, string $store): mixed
    {
        [$query, $filters, $page] = LedgerSearch::parse($r);
        $selected = array_intersect_key($filters, array_flip(['batch_id', 'q', 'source_type', 'bucket']));
        $payload = $this->api->request('GET', "stores/$store/inventory/ledger", [...$query, 'page' => $page])->json();
        abort_unless(is_array($payload) && data_get($payload, 'store.id') === $store && is_array($payload['data'] ?? null), 503, 'Cakupan riwayat stok API tidak valid.');
        foreach ($payload['data'] as $entry) {
            abort_unless(is_array($entry) && ($entry['store_id'] ?? null) === $store
                && (! isset($selected['batch_id']) || ($entry['inventory_batch_id'] ?? null) === $selected['batch_id']), 503, 'Catatan stok bukan milik toko atau batch yang dipilih.');
        }
        abort_unless(is_int($payload['total'] ?? null) && $payload['total'] >= 0
            && is_int($payload['per_page'] ?? null) && $payload['per_page'] > 0
            && ($payload['current_page'] ?? null) === $page, 503, 'Pagination stok API tidak valid.');
        $entries = new LengthAwarePaginator($payload['data'], $payload['total'], $payload['per_page'], $page, ['path' => url()->current(), 'query' => $filters]);

        return response()->view('pages.operations.stock-ledger', ['storeId' => $store, 'metadata' => $payload, 'filters' => $filters, 'entries' => $entries, 'sources' => self::SOURCES, 'buckets' => self::BUCKETS])
            ->header('Cache-Control', 'private, no-store');
    }
}
