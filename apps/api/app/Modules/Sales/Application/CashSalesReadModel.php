<?php

namespace App\Modules\Sales\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CashSalesReadModel
{
    private function query(string $org, array $stores, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('sales as s')->where('s.organization_id', $org)->whereIn('s.store_id', $stores)->where('s.status', 'completed')->where('s.payment_method', 'cash')->where('s.currency', 'IDR')->where('s.sold_at', '>=', $from)->where('s.sold_at', '<', $to);
    }

    public function stores(string $org, array $stores, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->query($org, $stores, $from, $to)->groupBy('s.store_id')->select('s.store_id')->selectRaw('COUNT(*) AS transaction_count, SUM(s.subtotal) AS gross_sales, SUM(s.discount_amount) AS discount_amount, SUM(s.total_amount) AS net_sales')->get()->map(function ($row) {
            $values = (array) $row;
            foreach (['transaction_count', 'gross_sales', 'discount_amount', 'net_sales'] as $key) {
                $values[$key] = (int) $values[$key];
            }

            return $values;
        })->all();
    }

    public function products(string $org, array $stores, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $p = $this->query($org, $stores, $from, $to)->join('sale_lines as l', 'l.sale_id', '=', 's.id')
            ->groupBy('l.product_id', 'l.sku', 'l.name', 'l.condition')->select('l.product_id', 'l.sku', 'l.name', 'l.condition')
            ->selectRaw('SUM(l.quantity * l.pieces_per_unit) AS sold_pieces, SUM(l.line_total) AS gross_sales, COUNT(DISTINCT s.id) AS transaction_count')
            ->orderBy('l.sku')->orderBy('l.product_id')->orderBy('l.name')->orderBy('l.condition')->paginate(50);
        $p->through(function ($row) {
            $values = (array) $row;
            foreach (['sold_pieces', 'gross_sales', 'transaction_count'] as $key) {
                $values[$key] = (int) $values[$key];
            }

            return $values;
        });

        return $p->toArray();
    }

    public function export(string $org, array $stores, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->query($org, $stores, $from, $to)->orderBy('s.sold_at')->orderBy('s.id')->limit(10001)->get(['s.number', 's.store_id', 's.store_name', 's.sold_at', 's.cashier_name', 's.currency', 's.subtotal', 's.discount_amount', 's.total_amount', 's.cash_received', 's.change_amount']);
        if ($rows->count() > 10000) {
            throw ValidationException::withMessages(['period' => 'Ekspor maksimal 10.000 transaksi; persempit toko atau periode.']);
        }

        return $rows->map(fn ($row) => (array) $row)->all();
    }
}
