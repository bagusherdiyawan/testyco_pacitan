<?php

namespace App\Modules\Reporting\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class CashReportController
{
    public function __construct(private Backend $api) {}

    private function filters(Request $r): array
    {
        $v = $r->validate(['from_day' => 'sometimes|date_format:Y-m-d', 'to_day' => 'sometimes|date_format:Y-m-d', 'store_id' => 'sometimes|nullable|ulid', 'page' => 'sometimes|integer|min:1']);
        $today = CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d');
        $from = CarbonImmutable::parse($v['from_day'] ?? $today, 'Asia/Jakarta')->startOfDay();
        $to = CarbonImmutable::parse($v['to_day'] ?? $today, 'Asia/Jakarta')->startOfDay()->addDay();
        abort_unless($to->greaterThan($from) && $to->timestamp - $from->timestamp <= 31 * 86400, 422, 'Rentang tanggal maksimal 31 hari dan akhir tidak sebelum awal.');

        return [['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'store_id' => $v['store_id'] ?? null], ['from_day' => $from->format('Y-m-d'), 'to_day' => $to->subDay()->format('Y-m-d'), 'store_id' => $v['store_id'] ?? null, 'page' => (int) ($v['page'] ?? 1)]];
    }

    public function index(Request $r, string $organization): mixed
    {
        [$query, $filters] = $this->filters($r);
        $path = "organizations/$organization/reports/cash-sales";
        $summary = $this->api->request('GET', $path, $query)->json('data');
        $products = $this->api->request('GET', $path.'/products', [...$query, 'page' => $filters['page']])->json();
        $page = new LengthAwarePaginator($products['data'], $products['total'], $products['per_page'], $products['current_page'], ['path' => url()->current(), 'query' => $filters]);

        return response()->view('pages.reporting.cash-sales', ['organization' => $organization, 'summary' => $summary, 'products' => $page, 'filters' => $filters])->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $r, string $organization): mixed
    {
        [$query] = $this->filters($r);
        $response = $this->api->request('GET', "organizations/$organization/reports/cash-sales/export", $query);
        abort_unless(str_starts_with(strtolower($response->header('Content-Type')), 'text/csv'), 503, 'Format ekspor API tidak valid.');

        return response($response->body(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="cash-sales.csv"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
