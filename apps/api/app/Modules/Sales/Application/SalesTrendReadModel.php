<?php

namespace App\Modules\Sales\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily sales for dashboards: every confirmed payment method (cash, bank transfer, manual QRIS), net of voucher
 * discounts, grouped by Asia/Jakarta calendar day. Refunds are reported on the day they were made, not netted
 * into the sale day. Read only; never writes ledgers or shifts.
 */
final class SalesTrendReadModel
{
    private const NONCASH = ['bank_transfer', 'qris_manual'];

    private function dayExpression(string $column): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "(($column AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Jakarta')::date",
            default => "date($column, '+7 hours')",
        };
    }

    /** @return array<int, string> */
    private function dates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];
        $day = $from->setTimezone('Asia/Jakarta')->startOfDay();
        $end = $to->setTimezone('Asia/Jakarta')->subSecond()->startOfDay();
        for (; $day <= $end; $day = $day->addDay()) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    public function trend(string $org, array $stores, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $day = $this->dayExpression('s.sold_at');
        $sales = DB::table('sales as s')->where('s.organization_id', $org)->whereIn('s.store_id', $stores)->where('s.status', 'completed')->where('s.currency', 'IDR')->where('s.sold_at', '>=', $from)->where('s.sold_at', '<', $to)
            ->selectRaw("$day AS day, s.payment_method, COUNT(*) AS transactions, SUM(s.total_amount) AS amount")->groupByRaw("$day, s.payment_method")->get();
        $refundDay = $this->dayExpression('r.refunded_at');
        $refunds = DB::table('sale_refunds as r')->join('sales as s', 's.id', '=', 'r.sale_id')->where('s.organization_id', $org)->whereIn('s.store_id', $stores)->where('r.refunded_at', '>=', $from)->where('r.refunded_at', '<', $to)
            ->selectRaw("$refundDay AS day, SUM(r.amount) AS amount")->groupByRaw($refundDay)->get()->pluck('amount', 'day');
        $days = [];
        foreach ($this->dates($from, $to) as $date) {
            $days[$date] = ['date' => $date, 'transactions' => 0, 'cash' => 0, 'noncash' => 0, 'refunds' => (int) ($refunds[$date] ?? 0)];
        }
        foreach ($sales as $row) {
            if (! isset($days[$row->day])) {
                continue;
            }
            $days[$row->day]['transactions'] += (int) $row->transactions;
            $key = in_array($row->payment_method, self::NONCASH, true) ? 'noncash' : 'cash';
            $days[$row->day][$key] += (int) $row->amount;
        }
        $rows = array_values($days);
        $totals = ['transactions' => 0, 'cash' => 0, 'noncash' => 0, 'refunds' => 0];
        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] += $row[$key];
            }
        }
        $totals['revenue'] = $totals['cash'] + $totals['noncash'];
        $totals['net_revenue'] = $totals['revenue'] - $totals['refunds'];

        return ['days' => $rows, 'totals' => $totals, 'top_products' => $this->topProducts($org, $stores, $from, $to)];
    }

    /** @return array<int, array<string, mixed>> */
    private function topProducts(string $org, array $stores, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('sales as s')->join('sale_lines as l', 'l.sale_id', '=', 's.id')->where('s.organization_id', $org)->whereIn('s.store_id', $stores)->where('s.status', 'completed')->where('s.currency', 'IDR')->where('s.sold_at', '>=', $from)->where('s.sold_at', '<', $to)
            ->groupBy('l.sku', 'l.name')->select('l.sku', 'l.name')->selectRaw('SUM(l.quantity * l.pieces_per_unit) AS sold_pieces, SUM(l.line_total) AS gross_sales')->orderByDesc('gross_sales')->orderBy('l.sku')->limit(5)->get()
            ->map(fn ($r) => ['sku' => $r->sku, 'name' => $r->name, 'sold_pieces' => (int) $r->sold_pieces, 'gross_sales' => (int) $r->gross_sales])->all();
    }
}
