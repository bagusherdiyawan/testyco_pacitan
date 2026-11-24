<?php

namespace App\Shared\Application;

use App\Modules\Identity\Infrastructure\Backend;
use Carbon\CarbonImmutable;

/**
 * Builds the dashboard numbers for the first (or chosen) organization the user can report on.
 * Authorization stays in the API; a missing or malformed answer simply hides the dashboard.
 */
final class SalesDashboard
{
    public const REPORT_ROLES = ['owner', 'admin', 'partner_lead', 'store_manager'];

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public function __construct(private Backend $api) {}

    /** @param iterable<array<string, mixed>> $organizations */
    public function build(iterable $organizations, ?string $requested): ?array
    {
        $reportable = [];
        foreach ($organizations as $org) {
            if (($org['active'] ?? false) && in_array($org['role'] ?? null, self::REPORT_ROLES, true)) {
                $reportable[$org['id']] = $org;
            }
        }
        if (! $reportable) {
            return null;
        }
        $org = $reportable[$requested] ?? reset($reportable);
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();
        $from = $today->subDays(6);
        $to = $today->addDay();
        try {
            $data = $this->api->request('GET', "organizations/{$org['id']}/reports/sales-trend", ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()])->json('data');
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($data) || ($data['organization']['id'] ?? null) !== $org['id'] || ! is_array($data['days'] ?? null) || ! is_array($data['totals'] ?? null)) {
            return null;
        }
        $days = collect($data['days'])->map(function ($d) {
            $date = CarbonImmutable::parse($d['date'], 'Asia/Jakarta');

            return [...$d, 'label' => $date->format('j').' '.self::MONTHS[$date->month - 1]];
        })->values();
        $last = $days->last() ?? ['cash' => 0, 'noncash' => 0, 'transactions' => 0];
        $totals = $data['totals'];

        return [
            'organization' => ['id' => $org['id'], 'name' => $org['name']],
            'others' => collect($reportable)->except($org['id'])->map(fn ($o) => ['id' => $o['id'], 'name' => $o['name']])->values()->all(),
            'period' => $from->format('j M').' – '.$today->format('j M Y'),
            'today' => ['revenue' => (int) $last['cash'] + (int) $last['noncash'], 'transactions' => (int) $last['transactions']],
            'totals' => $totals,
            'has_sales' => ($totals['transactions'] ?? 0) > 0,
            'trend' => ['labels' => $days->pluck('label')->all(), 'cash' => $days->pluck('cash')->all(), 'noncash' => $days->pluck('noncash')->all()],
            'products' => ['labels' => collect($data['top_products'] ?? [])->map(fn ($p) => $p['name'])->all(), 'values' => collect($data['top_products'] ?? [])->pluck('gross_sales')->all()],
        ];
    }
}
