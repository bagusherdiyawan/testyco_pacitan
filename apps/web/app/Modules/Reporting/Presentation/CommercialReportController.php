<?php

namespace App\Modules\Reporting\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\CsvDownload;
use App\Shared\Application\PendingMutation;
use App\Shared\Application\WibPeriod;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class CommercialReportController
{
    public function __construct(private Backend $api, private PendingMutation $mutations) {}

    private function filters(Request $r): array
    {
        $v = $r->validate(['from_day' => 'sometimes|date_format:Y-m-d', 'to_day' => 'sometimes|date_format:Y-m-d', 'page' => 'sometimes|integer|min:1']);
        $period = WibPeriod::fromDays($v['from_day'] ?? null, $v['to_day'] ?? null);

        return [$period->api(), [...$period->calendar(), 'page' => (int) ($v['page'] ?? 1)]];
    }

    private function read(string $org, array $q): array
    {
        $c = $this->api->request('GET', "organizations/$org/reports/commercial", $q)->json();
        abort_unless(($c['organization_id'] ?? null) === $org && in_array($c['side'] ?? null, ['seller', 'buyer'], true) && ($c['currency'] ?? null) === 'IDR' && ($c['basis'] ?? null) === 'document_cohort_current_adjustments' && is_array($c['data'] ?? null) && count($c['data']) <= 10000 && is_array($c['metrics'] ?? null) && is_array($c['balances'] ?? null) && is_string($c['observed_at'] ?? null), 503);
        foreach ($c['data'] as $row) {
            abort_unless(($row['organization_id'] ?? null) === $org && is_string($row['id'] ?? null) && in_array($row['source_type'] ?? null, ['pos_sale', $c['side'] === 'seller' ? 'wholesale_invoice' : 'purchase_invoice'], true), 503);
            foreach (['gross_amount', 'discount_amount', 'refund_credit_amount', 'net_goods_amount', 'cost_version'] as $key) {
                abort_unless(is_int($row[$key] ?? null), 503);
            }foreach (['goods_cost', 'operating_cost', 'profit_amount'] as $key) {
                abort_unless(array_key_exists($key, $row) && ($row[$key] === null || is_int($row[$key])), 503);
            }
        }abort_unless(strlen(json_encode($c['data'], JSON_THROW_ON_ERROR)) <= 8 * 1024 * 1024, 503);

        return $c;
    }

    public function index(Request $r, string $organization): mixed
    {
        [$q,$filters] = $this->filters($r);
        $pending = session('commercial_cost_pending.'.$organization);
        $c = $pending ? null : $this->read($organization, $q);
        $rows = $c ? new LengthAwarePaginator(array_slice($c['data'], ($filters['page'] - 1) * 50, 50), count($c['data']), 50, $filters['page'], ['path' => url()->current(), 'query' => $filters]) : null;

        return response()->view('pages.reporting.commercial', compact('organization', 'pending', 'c', 'filters', 'rows'))->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $r, string $organization): mixed
    {
        [$q] = $this->filters($r);
        $c = $this->read($organization, $q);

        return CsvDownload::response('commercial-report.csv', ['Jenis', 'Nomor', 'Waktu dokumen', 'Metode/model', 'Bruto', 'Diskon', 'Refund/kredit saat baca', 'Nilai barang bersih', 'HPP tercatat', 'Biaya operasional', 'Laba dengan bukti', 'Versi biaya'], array_map(fn ($row) => array_map(fn ($key) => $row[$key] ?? 'Belum ada bukti', ['source_type', 'number', 'occurred_at', 'method', 'gross_amount', 'discount_amount', 'refund_credit_amount', 'net_goods_amount', 'goods_cost', 'operating_cost', 'profit_amount', 'cost_version']), $c['data']));
    }

    public function cost(Request $r, string $organization): mixed
    {
        abort_if(session()->has('commercial_cost_pending.'.$organization), 409);
        $v = $r->validate(['operation_id' => 'required|uuid', 'source_type' => 'required|in:pos_sale,wholesale_invoice', 'source_id' => 'required|ulid', 'version' => 'required|integer|min:0', 'goods_cost' => 'required|integer|min:0|max:1000000000000', 'operating_cost' => 'required|integer|min:0|max:1000000000000', 'evidence_reference' => 'required|string|max:2000', 'reason' => 'required|string|max:2000']);
        foreach (['version', 'goods_cost', 'operating_cost'] as $key) {
            $v[$key] = (int) $v[$key];
        }

        return $this->send($organization, ['path' => "organizations/$organization/reports/commercial/costs", 'body' => $v, 'target' => "/app/organizations/$organization/reports/commercial"]);
    }

    public function retry(string $organization): mixed
    {
        $p = session('commercial_cost_pending.'.$organization);
        abort_unless(is_array($p), 409);

        return $this->send($organization, $p);
    }

    private function send(string $org, array $p): mixed
    {
        return $this->mutations->send('commercial_cost_pending.'.$org, $p, fn ($d, $p) => ($d['organization_id'] ?? null) === $org && ($d['source_type'] ?? null) === $p['body']['source_type'] && ($d['source_id'] ?? null) === $p['body']['source_id'] && ($d['version'] ?? null) === $p['body']['version'] + 1 && ($d['goods_cost'] ?? null) === $p['body']['goods_cost'] && ($d['operating_cost'] ?? null) === $p['body']['operating_cost'] && ($d['evidence_reference'] ?? null) === $p['body']['evidence_reference']);
    }
}
