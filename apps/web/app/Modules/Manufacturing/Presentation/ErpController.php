<?php

namespace App\Modules\Manufacturing\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ErpController
{
    public const PRODUCTION_SECTIONS = ['materials', 'suppliers', 'purchases', 'lots', 'recipes', 'productions', 'counts', 'ledger', 'planning', 'report', 'trace'];

    public const SECTIONS = ['consignor_values' => 'Stok konsinyasi milik pemasok', 'consignor_ledger' => 'Jejak HPP aktual konsinyasi', 'distribution_settlements' => 'Biaya pengganti, hilang dan retur', 'closings' => 'Penutupan buku formal', 'income_statement' => 'Laporan laba rugi', 'balance_sheet' => 'Posisi keuangan', 'invoice_variances' => 'Penyesuaian biaya invoice', 'distribution_costs' => 'Biaya historis distribusi', 'cost_ledger' => 'Jejak nilai / HPP batch', 'posting' => 'Pemetaan jurnal otomatis', 'posting_events' => 'Antrean jurnal sumber', 'valuations' => 'Review nilai saldo awal', 'valuation_stock' => 'Nilai persediaan produk', 'operators' => 'Izin operator ERP', 'accounts' => 'Akun buku besar', 'periods' => 'Periode pembukuan', 'journals' => 'Jurnal debit/kredit', 'trial_balance' => 'Neraca saldo dan ringkasan akun', 'materials' => 'Bahan baku / kemasan', 'suppliers' => 'Pemasok', 'purchases' => 'Pembelian dan pembayaran', 'lots' => 'Lot, pemeriksaan dan stok bahan', 'recipes' => 'Resep produksi', 'productions' => 'Pelaksanaan dan pemeriksaan produksi', 'counts' => 'Review opname bahan', 'ledger' => 'Pergerakan bahan', 'planning' => 'Kebutuhan bahan', 'report' => 'Biaya produksi / HPP', 'trace' => 'Jejak bahan ke hasil produksi'];

    public const ACTIONS = ['consignor_enable', 'consignor_retry', 'consignor_dispose', 'missing_propose', 'missing_decide', 'distribution_retry', 'commercial_policy', 'closing_propose', 'closing_decide', 'invoice_revalue_retry', 'wholesale_policy', 'posting_policy', 'posting_decide', 'posting_suspend', 'posting_reconcile', 'posting_retry', 'value_propose', 'value_decide', 'operator_grant', 'account', 'accounting_period', 'journal', 'journal_decide', 'journal_reverse', 'period_status', 'material', 'material_configure', 'supplier', 'purchase', 'purchase_decide', 'receive', 'payment_reverse', 'payment', 'recipe', 'production', 'production_cancel', 'complete', 'material_quality', 'production_quality', 'transfer', 'count', 'count_decide'];

    public function __construct(private Backend $api, private PendingMutation $pending) {}

    private function path(string $org): string
    {
        return 'organizations/'.$org.'/erp';
    }

    private function target(string $org, array $q): string
    {
        return '/app/'.$this->path($org).'?'.http_build_query($q);
    }

    private function query(Request $r): array
    {
        return $r->validate(['section' => ['sometimes', Rule::in(array_keys(self::SECTIONS))], 'page' => 'sometimes|integer|min:1', 'store_id' => 'nullable|ulid', 'material_id' => 'nullable|ulid', 'lot_id' => 'nullable|ulid', 'period_id' => 'nullable|ulid', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
    }

    private function context(string $org, array $q): array
    {
        $c = $this->api->request('GET', $this->path($org).'/context', $q)->json();
        abort_unless(is_array($c) && ($c['organization_id'] ?? null) === $org && ($c['section'] ?? null) === ($q['section'] ?? 'materials') && (($c['can_manage'] ?? false) === true || ($c['can_operate'] ?? false) === true) && is_array($c['data'] ?? null) && ($c['current_page'] ?? null) === (int) ($q['page'] ?? 1) && is_array($c['forms'] ?? null), 503, 'Lingkup ERP tidak cocok.');
        if (isset($q['period_id']) && in_array($q['section'] ?? '', ['closings', 'income_statement', 'balance_sheet'], true)) {
            abort_unless(($c['selected_period_id'] ?? null) === $q['period_id'], 503, 'Periode laporan tidak cocok.');
        }
        foreach ($c['data'] as $row) {
            if (isset($q['store_id']) && in_array($q['section'] ?? '', ['productions', 'lots'], true)) {
                abort_unless(($row['store_id'] ?? null) === $q['store_id'], 503);
            }
            if (isset($q['period_id']) && in_array($q['section'] ?? '', ['journals', 'closings'], true)) {
                abort_unless(($row['period_id'] ?? null) === $q['period_id'], 503);
            }
            abort_unless(($row['organization_id'] ?? null) === $org && is_string($row['id'] ?? null) && is_string($row['summary'] ?? null) && is_array($row['forms'] ?? null), 503);
        }
        foreach ([...$c['forms'], ...collect($c['data'])->flatMap(fn ($r) => $r['forms'])->all()] as $form) {
            if (($c['can_manage'] ?? false) !== true) {
                abort_unless(in_array($form['operation'] ?? null, ['production', 'complete', 'production_cancel'], true), 503);
            }
            $this->form($form);
        }

        return $c;
    }

    private function form(array $f): void
    {
        abort_unless(in_array($f['operation'] ?? null, self::ACTIONS, true) && is_string($f['key'] ?? null) && is_array($f['fields'] ?? null) && is_array($f['fixed'] ?? null), 503);
        foreach ($f['fields'] as $field) {
            abort_unless(is_string($field['key'] ?? null) && preg_match('/^[a-z_]+$/D', $field['key']) && is_string($field['label'] ?? null) && in_array($field['kind'] ?? null, ['text', 'integer', 'date', 'choice', 'quantity_map', 'money_map', 'consent'], true) && is_array($field['options'] ?? null), 503);
        }
    }

    public function index(Request $r, string $organization): mixed
    {
        $q = $this->query($r);
        $pending = session('erp_pending.'.$organization);
        $candidate = session('erp_candidate.'.$organization);
        $context = $items = null;
        if (! $pending && ! $candidate) {
            $context = $this->context($organization, $q);
            $items = new LengthAwarePaginator($context['data'], $context['total'] ?? count($context['data']), $context['per_page'] ?? max(1, count($context['data'])), $context['current_page'], ['path' => url()->current(), 'query' => $q]);
        }

        return response()->view('pages.erp', compact('organization', 'q', 'pending', 'candidate', 'context', 'items'))->header('Cache-Control', 'private, no-store');
    }

    public function review(Request $r, string $organization): mixed
    {
        abort_if(session()->has('erp_pending.'.$organization), 409, 'Selesaikan permintaan belum pasti.');
        $q = $this->query($r);
        $head = $r->validate(['form_key' => 'required|string|max:160', 'fixed_json' => 'required|string|max:250000']);
        $c = $this->context($organization, $q);
        $catalog = [...$c['forms'], ...collect($c['data'])->flatMap(fn ($row) => $row['forms'])->all()];
        $f = collect($catalog)->firstWhere('key', $head['form_key']);
        abort_unless($f, 409, 'Tindakan sudah berubah. Muat ulang dan periksa lagi.');
        $fixed = json_decode($head['fixed_json'], true);
        abort_unless(is_array($fixed) && $fixed == $f['fixed'], 409, 'Snapshot berubah. Muat ulang dan tinjau kembali.');
        $values = $r->input('values', []);
        foreach ($f['fields'] as $field) {
            if (in_array($field['kind'], ['quantity_map', 'money_map'], true) && is_array($values[$field['key']] ?? null)) {
                $values[$field['key']] = array_filter($values[$field['key']], fn ($x) => $x !== null && $x !== '');
            }
        }
        $r->merge(['values' => $values]);
        $rules = [];
        foreach ($f['fields'] as $field) {
            $key = 'values.'.$field['key'];
            $required = $field['optional'] ? 'nullable' : 'required';
            if (in_array($field['kind'], ['quantity_map', 'money_map'], true)) {
                $rules[$key] = [$required, 'array', 'max:100'];
                $rules[$key.'.*'] = $field['kind'] === 'money_map' ? 'nullable|integer|min:0|max:1000000000000' : 'nullable|string|max:20';
            } else {
                $rules[$key] = match ($field['kind']) {
                    'integer' => [$required, 'integer', 'min:0', 'max:1000000000000'],'date' => [$required, 'date_format:Y-m-d'],'choice' => [$required, Rule::in(array_keys($field['options']))],'consent' => ['required', 'accepted'],default => [$required, 'string', 'max:10000']
                };
            }
        }
        $v = $r->validate($rules);
        $body = $fixed;
        $display = [];
        foreach ($f['fields'] as $field) {
            $value = data_get($v, 'values.'.$field['key']);
            $kind = $field['kind'];
            if (in_array($kind, ['quantity_map', 'money_map'], true)) {
                $value = array_filter($value ?? [], fn ($x) => $x !== null && $x !== '');
                abort_if(array_diff(array_keys($value), array_keys($field['options'])), 422, 'Pilihan bahan/lot tidak sesuai.');
                $display[$field['label']] = implode('; ', array_map(fn ($id) => $field['options'][$id].': '.$value[$id], array_keys($value)));
            } else {
                $display[$field['label']] = $kind === 'consent' ? 'Setuju' : ($field['options'][$value] ?? $value ?? 'Tidak diisi');
            }
            if ($kind === 'integer' && $value !== null) {
                $value = (int) $value;
            }
            if ($kind === 'consent') {
                $value = true;
            }
            $body[$field['key']] = $value;
        }
        $body['operation_id'] = (string) Str::uuid();
        $snapshot = collect($c['data'])->firstWhere('id', $f['subject_id']);
        session()->put('erp_candidate.'.$organization, ['path' => $this->path($organization).'/'.$f['operation'], 'body' => $body, 'form' => $f, 'display' => $display, 'snapshot' => $snapshot['summary'] ?? '', 'organization' => $organization, 'actor' => session('api_user.id'), 'target' => $this->target($organization, $q)]);

        return redirect($this->target($organization, $q));
    }

    public function confirm(string $organization): mixed
    {
        abort_if(session()->has('erp_pending.'.$organization), 409);
        $p = session('erp_candidate.'.$organization);
        abort_unless(is_array($p), 409);
        session()->forget('erp_candidate.'.$organization);

        return $this->send($organization, $p);
    }

    public function retry(string $organization): mixed
    {
        $p = session('erp_pending.'.$organization);
        abort_unless(is_array($p), 409);

        return $this->send($organization, $p);
    }

    private function send(string $org, array $p): mixed
    {
        abort_unless($p['organization'] === $org && $p['actor'] === session('api_user.id'), 403);

        return $this->pending->send('erp_pending.'.$org, $p, fn ($d, $p) => ($d['organization_id'] ?? null) === $org && is_string($d['id'] ?? null) && is_int($d['version'] ?? null) && ($d['operation_id'] ?? null) === $p['body']['operation_id'] && (! $p['form']['expected_id'] || $d['id'] === $p['form']['expected_id']) && (! $p['form']['expected_version'] || $d['version'] === $p['form']['expected_version']));
    }

    public function edit(string $organization): mixed
    {
        abort_if(session()->has('erp_pending.'.$organization), 409);
        $p = session('erp_candidate.'.$organization);
        session()->forget('erp_candidate.'.$organization);

        return redirect($p['target'] ?? '/app/'.$this->path($organization));
    }
}
