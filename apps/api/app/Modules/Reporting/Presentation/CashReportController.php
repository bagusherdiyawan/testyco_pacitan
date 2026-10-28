<?php

namespace App\Modules\Reporting\Presentation;

use App\Modules\Identity\Application\Audit;
use App\Modules\Reporting\Application\ReportAccess;
use App\Modules\Reporting\Domain\CsvText;
use App\Modules\Reporting\Domain\ReportPeriod;
use App\Modules\Sales\Application\CashSalesReadModel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class CashReportController
{
    public function __construct(private ReportAccess $access, private CashSalesReadModel $read) {}

    private function scope(Request $r, string $organization): array
    {
        $v = $r->validate(['from' => ['required', 'string', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'], 'to' => ['required', 'string', 'date', 'after:from', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'], 'store_id' => 'sometimes|nullable|ulid', 'page' => 'sometimes|integer|min:1']);
        $scope = $this->access->scope($r->user(), $organization, $v['store_id'] ?? null);

        try {
            $period = new ReportPeriod(CarbonImmutable::parse($v['from'])->utc(), CarbonImmutable::parse($v['to'])->utc());
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['period' => $e->getMessage()]);
        }

        return [$scope, $period];
    }

    public function summary(Request $r, string $organization): mixed
    {
        [$scope, $period] = $this->scope($r, $organization);
        $rows = $this->read->stores($organization, $scope['store_ids'], $period->from, $period->to);
        $zero = ['transaction_count' => 0, 'gross_sales' => 0, 'discount_amount' => 0, 'net_sales' => 0];
        $totals = $zero;
        $byStore = [];
        foreach ($scope['available_stores'] as $store) {
            if (! in_array($store['id'], $scope['store_ids'], true)) {
                continue;
            }
            $metric = collect($rows)->firstWhere('store_id', $store['id']) ?? $zero;
            foreach ($zero as $key => $_) {
                $totals[$key] += $metric[$key];
            }
            $byStore[] = ['store_id' => $store['id'], 'store_name' => $store['name'], 'active' => $store['active'], ...array_intersect_key($metric, $zero)];
        }

        return response()->json(['data' => ['organization' => $scope['organization'], 'available_stores' => $scope['available_stores'], ...$period->toArray(), 'currency' => 'IDR', 'scope' => 'server_confirmed_cash_sales', 'observed_at' => now()->toIso8601String(), 'totals' => $totals, 'by_store' => $byStore]])->header('Cache-Control', 'private, no-store');
    }

    public function products(Request $r, string $organization): mixed
    {
        [$scope, $period] = $this->scope($r, $organization);

        return response()->json([...$this->read->products($organization, $scope['store_ids'], $period->from, $period->to), ...$period->toArray(), 'observed_at' => now()->toIso8601String(), 'currency' => 'IDR'])->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $r, string $organization): mixed
    {
        [$scope, $period] = $this->scope($r, $organization);
        $rows = $this->read->export($organization, $scope['store_ids'], $period->from, $period->to);
        Audit::record($r->user()->id, $organization, 'report.cash_export_prepared', $organization, null, [...$period->toArray(), 'store_ids' => $scope['store_ids'], 'row_count' => count($rows)]);

        return response()->streamDownload(function () use ($rows) {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['number', 'store_id', 'store_name', 'sold_at_utc', 'cashier_name', 'currency', 'gross_sales_idr', 'discount_idr', 'net_sales_idr', 'cash_received_idr', 'change_idr'], ',', '"', '');
            foreach ($rows as $row) {
                $row['sold_at'] = CarbonImmutable::parse($row['sold_at'], 'UTC')->toIso8601String();
                $cells = [];
                foreach ($row as $key => $value) {
                    $cells[] = in_array($key, ['subtotal', 'discount_amount', 'total_amount', 'cash_received', 'change_amount'], true) ? (int) $value : CsvText::safe((string) $value);
                }
                fputcsv($stream, $cells, ',', '"', '');
            }
            fclose($stream);
        }, 'cash-sales.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
