<?php

namespace App\Modules\Operations\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class BatchExpiryController
{
    public function __construct(private Backend $api) {}

    public function index(Request $r, string $store): mixed
    {
        $v = $r->validate([
            'page' => 'sometimes|integer|min:1', 'q' => 'nullable|string|max:100',
            'expires_from' => 'nullable|string|date_format:Y-m-d', 'expires_to' => 'nullable|string|date_format:Y-m-d',
            'with_balance' => 'sometimes|boolean',
        ]);
        abort_if(isset($v['expires_from'], $v['expires_to']) && $v['expires_from'] > $v['expires_to'], 422, 'Tanggal akhir kedaluwarsa tidak boleh mendahului awal.');
        $filters = array_filter(array_intersect_key($v, array_flip(['q', 'expires_from', 'expires_to', 'with_balance'])), fn ($value) => $value !== null && $value !== '');
        $page = (int) ($v['page'] ?? 1);
        $payload = $this->api->request('GET', "stores/$store/inventory/batches", [...$filters, 'page' => $page])->json();
        abort_unless(is_array($payload) && data_get($payload, 'store.id') === $store && is_array($payload['data'] ?? null), 503, 'Cakupan katalog batch API tidak valid.');
        foreach ($payload['data'] as $batch) {
            abort_unless(is_array($batch) && ($batch['store_id'] ?? null) === $store, 503, 'Batch bukan milik toko yang dipilih.');
        }
        abort_unless(is_int($payload['total'] ?? null) && $payload['total'] >= 0 && is_int($payload['per_page'] ?? null) && $payload['per_page'] > 0 && ($payload['current_page'] ?? null) === $page, 503, 'Pagination batch API tidak valid.');
        $batches = new LengthAwarePaginator($payload['data'], $payload['total'], $payload['per_page'], $page, ['path' => url()->current(), 'query' => $filters]);

        return response()->view('pages.operations.batch-expiry', ['store' => $store, 'metadata' => $payload, 'filters' => $filters, 'batches' => $batches])->header('Cache-Control', 'private, no-store');
    }
}
