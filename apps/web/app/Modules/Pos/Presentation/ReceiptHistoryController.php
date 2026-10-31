<?php

namespace App\Modules\Pos\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\WibPeriod;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class ReceiptHistoryController
{
    public function __construct(private Backend $api) {}

    public function index(Request $r, string $store): mixed
    {
        $v = $r->validate([
            'from_day' => 'nullable|required_with:to_day|date_format:Y-m-d',
            'to_day' => 'nullable|required_with:from_day|date_format:Y-m-d',
            'q' => 'nullable|string|max:100', 'page' => 'sometimes|integer|min:1',
        ]);
        $query = [];
        $filters = [];
        if (isset($v['from_day'], $v['to_day'])) {
            $period = WibPeriod::fromDays($v['from_day'], $v['to_day']);
            $query = $period->api();
            $filters = $period->calendar();
        }
        if (($v['q'] ?? '') !== '') {
            $query['q'] = $filters['q'] = $v['q'];
        }
        $page = (int) ($v['page'] ?? 1);
        $payload = $this->api->request('GET', "stores/$store/sales", [...$query, 'page' => $page])->json();
        abort_unless(is_array($payload) && data_get($payload, 'store.id') === $store
            && in_array($payload['scope'] ?? null, ['own', 'store'], true)
            && is_array($payload['data'] ?? null), 503, 'Cakupan riwayat struk API tidak valid.');
        foreach ($payload['data'] as $sale) {
            abort_unless(is_array($sale) && ($sale['store_id'] ?? null) === $store, 503, 'Struk bukan milik toko yang dipilih.');
        }
        abort_unless(is_int($payload['total'] ?? null) && $payload['total'] >= 0
            && is_int($payload['per_page'] ?? null) && $payload['per_page'] > 0
            && ($payload['current_page'] ?? null) === $page, 503, 'Pagination struk API tidak valid.');
        $sales = new LengthAwarePaginator($payload['data'], $payload['total'], $payload['per_page'], $page, ['path' => url()->current(), 'query' => $filters]);

        return response()->view('pages.pos.history', ['store' => $store, 'metadata' => $payload, 'sales' => $sales, 'filters' => $filters])
            ->header('Cache-Control', 'private, no-store');
    }
}
