<?php

namespace App\Modules\Manufacturing\Application;

use App\Models\User;
use App\Modules\Accounting\Application\AccountingAccess;
use App\Modules\Accounting\Application\AccountingReadModel;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Manufacturing\Infrastructure\Production;
use App\Modules\Manufacturing\Infrastructure\Recipe;
use App\Modules\Materials\Domain\Quantity;
use App\Modules\Materials\Infrastructure\Material;
use App\Modules\Materials\Infrastructure\MaterialCount;
use App\Modules\Materials\Infrastructure\MaterialLot;
use App\Modules\Procurement\Infrastructure\Purchase;
use App\Modules\Procurement\Infrastructure\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ErpReadModel
{
    public const SECTIONS = ['materials', 'suppliers', 'purchases', 'lots', 'recipes', 'productions', 'counts', 'ledger', 'planning', 'report', 'trace', 'operators', 'accounts', 'periods', 'journals', 'trial_balance', 'posting', 'posting_events', 'valuations', 'valuation_stock', 'cost_ledger', 'distribution_costs', 'invoice_variances'];

    public function __construct(private ErpAccess $access) {}

    private function bounded($query): array
    {
        $rows = $query->limit(1001)->get()->toArray();
        abort_if(count($rows) > 1000, 422, 'Pilihan melebihi1000. Pilih gudang/persempit data ERP.');

        return $rows;
    }

    private function options(string $org, ?string $store, string $section): array
    {
        $materials = in_array($section, ['purchases', 'lots', 'recipes', 'planning'], true) ? $this->bounded(Material::where('organization_id', $org)->where('active', true)->orderBy('name')) : [];
        $stores = $this->bounded(Store::where('organization_id', $org)->where('active', true)->orderBy('name'));
        $suppliers = $section === 'purchases' ? $this->bounded(Supplier::where('organization_id', $org)->orderBy('name')) : [];
        $products = $section === 'recipes' ? $this->bounded(Product::where('organization_id', $org)->where('active', true)->orderBy('name')) : [];
        $recipes = $section === 'productions' ? $this->bounded(Recipe::where('organization_id', $org)->where('active', true)->orderByDesc('id')) : [];
        $lots = in_array($section, ['productions', 'planning'], true) ? $this->bounded(MaterialLot::where('organization_id', $org)->when($store, fn ($q) => $q->where('store_id', $store))->where(fn ($q) => $q->where('usable_quantity', '>', 0)->orWhere('quarantined_quantity', '>', 0))->orderBy('expires_on')->orderBy('id')) : [];

        return ['stores' => array_column($stores, 'name', 'id'), 'materials' => collect($materials)->mapWithKeys(fn ($m) => [$m['id'] => $m['name'].' ('.$m['unit'].')'])->all(), 'material_rows' => $materials, 'suppliers' => array_column($suppliers, 'name', 'id'), 'products' => collect($products)->mapWithKeys(fn ($p) => [$p['id'] => $p['name'].' · '.$p['sku'].' · isi'.$p['pack_size']])->all(), 'recipes' => collect($recipes)->mapWithKeys(fn ($r) => [$r['id'] => $r['title'].' · versi'.$r['version'].' · hasil'.($r['output_kind'] === 'material' ? Quantity::display($r['yield_quantity'], $r['yield_unit']).$r['yield_unit'] : $r['output_packs'].'kemasan')])->all(), 'recipe_versions' => array_column($recipes, 'version', 'id'), 'lot_rows' => $lots];
    }

    public function context(User $u, string $org, array $v): array
    {
        $section = $v['section'] ?? 'materials';
        $store = $v['store_id'] ?? null;
        if (in_array($section, AccountingReadModel::SECTIONS, true)) {
            app(AccountingAccess::class)->manage($u, $org);

            return ['organization_id' => $org, 'section' => $section, 'can_manage' => true, 'allowed_sections' => $this->access->manager($u, $org) ? self::SECTIONS : AccountingReadModel::SECTIONS, 'observed_at' => now()->toIso8601String(), ...app(AccountingReadModel::class)->context($org, $section, $v, $u->id)];
        }
        $manager = $this->access->manager($u, $org);
        if (! $manager) {
            abort_unless(in_array($section, ['productions', 'lots', 'recipes'], true) && is_string($store), 403);
            $this->access->operator($u, $org, $store);
        }
        if ($store) {
            abort_unless(Store::where('organization_id', $org)->whereKey($store)->exists(), 403);
        }
        if (isset($v['material_id'])) {
            abort_unless(Material::where('organization_id', $org)->whereKey($v['material_id'])->exists(), 403);
        }
        $options = $this->options($org, $store, $section);
        if (! $manager) {
            $options['stores'] = array_intersect_key($options['stores'], [$store => true]);
        }
        if ($section === 'operators') {
            return $this->operators($org, $v, $options);
        }
        $page = (int) ($v['page'] ?? 1);
        $base = ['organization_id' => $org, 'section' => $section, 'observed_at' => now()->toIso8601String(), 'can_manage' => $manager, 'can_operate' => ! $manager, 'allowed_sections' => $manager ? self::SECTIONS : ['productions', 'lots', 'recipes'], 'filter_stores' => $options['stores'], 'filter_materials' => $options['materials'], 'forms' => ($manager || $section === 'productions') ? ErpForms::create($section, $options) : []];
        if (in_array($section, ['planning', 'report', 'trace'], true)) {
            return [...$base, ...$this->projection($org, $section, $v, $options), 'current_page' => 1, 'next_page_url' => null];
        }
        $class = match ($section) {
            'materials' => Material::class,'suppliers' => Supplier::class,'purchases' => Purchase::class,'lots' => MaterialLot::class,'recipes' => Recipe::class,'productions' => Production::class,'counts' => MaterialCount::class,default => null
        };
        $query = $class ? $class::where('organization_id', $org) : DB::table('erp_material_entries')->where('organization_id', $org);
        if ($store && in_array($section, ['lots', 'purchases', 'productions'], true)) {
            $query->where('store_id', $store);
        }
        if (isset($v['material_id']) && $section === 'lots') {
            $query->where('material_id', $v['material_id']);
        }
        if (isset($v['lot_id']) && in_array($section, ['counts', 'ledger'], true)) {
            abort_unless(MaterialLot::where('organization_id', $org)->whereKey($v['lot_id'])->exists(), 403);
            $query->where('material_lot_id', $v['lot_id']);
        }
        $p = $query->orderByDesc('id')->paginate(30);
        $rows = [];
        foreach ($p->items() as $item) {
            $row = is_object($item) && method_exists($item, 'toArray') ? $item->toArray() : (array) $item;
            if ($section === 'lots') {
                $row['version'] = $row['stock_revision'];
            }
            if ($section === 'productions' && $row['output_material_lot_id']) {
                $row['output_stock_revision'] = MaterialLot::where('organization_id', $org)->whereKey($row['output_material_lot_id'])->value('stock_revision');
            }
            if ($section === 'productions' && $row['output_batch_id']) {
                $row['output_stock_revision'] = InventoryBatch::where('organization_id', $org)->whereKey($row['output_batch_id'])->value('stock_revision');
            }
            $rows[] = [...$row, 'summary' => $this->summary($section, $row), 'forms' => $manager ? ErpForms::row($section, $row, $options, $u->id) : array_values(array_filter(ErpForms::row($section, $row, $options, $u->id), fn ($f) => in_array($f['operation'], ['complete', 'production_cancel'], true)))];
        }

        return [...$p->toArray(), ...$base, 'data' => $rows];
    }

    private function operators(string $org, array $v, array $options): array
    {
        $users = DB::table('store_user')->join('users', 'users.id', '=', 'store_user.user_id')->join('stores', 'stores.id', '=', 'store_user.store_id')->join('organization_user', function ($j) {
            $j->on('organization_user.user_id', '=', 'users.id')->on('organization_user.organization_id', '=', 'stores.organization_id');
        })->leftJoin('erp_operator_grants as grants', function ($j) {
            $j->on('grants.user_id', '=', 'users.id')->on('grants.store_id', '=', 'stores.id')->on('grants.organization_id', '=', 'stores.organization_id');
        })->where('stores.organization_id', $org)->where('stores.active', true)->where('users.active', true)->whereIn('organization_user.role', ['warehouse', 'store_manager'])->limit(1001)->get(['users.id', 'users.name', 'stores.id as store_id', 'stores.name as store_name', 'grants.version as grant_version']);
        abort_if($users->count() > 1000, 422);
        $assignments = [];
        $versions = [];
        foreach ($users as $u) {
            $assignments[$u->id.':'.$u->store_id] = $u->name.' · '.$u->store_name;
            if ($u->grant_version !== null) {
                $versions[$u->id.':'.$u->store_id] = $u->grant_version;
            }
        }
        $page = DB::table('erp_operator_grants')->where('organization_id', $org)->orderByDesc('id')->paginate(30);
        $rows = [];
        foreach ($page->items() as $g) {
            $key = $g->user_id.':'.$g->store_id;
            $revoke = ['key' => 'operator_grant-'.$g->id, 'operation' => 'operator_grant', 'label' => 'Cabut izin operator', 'fields' => [ErpForms::field('note', 'Alasan pencabutan')], 'fixed' => ['assignment' => $key, 'enabled' => false, 'grant_versions' => [$key => $g->version]], 'subject_id' => $g->id, 'expected_id' => $g->id, 'expected_version' => $g->version + 1];
            $rows[] = ['id' => $g->id, 'organization_id' => $org, 'summary' => ($assignments[$g->user_id.':'.$g->store_id] ?? 'Penugasan lama').' · produksi '.($g->enabled ? 'diizinkan' : 'dicabut').' · versi'.$g->version.' · '.$g->reason, 'forms' => $g->enabled ? [$revoke] : []];
        }
        $form = ['key' => 'operator_grant-', 'operation' => 'operator_grant', 'label' => 'Atur izin operator per gudang', 'fields' => [ErpForms::field('assignment', 'Pengguna dan gudang yang sudah ditugaskan', 'choice', $assignments), ErpForms::field('enabled', 'Izin produksi termasuk resep, pemakaian bahan dan biaya proses gudang ini', 'choice', ['1' => 'Berikan', '0' => 'Cabut']), ErpForms::field('note', 'Alasan pemberian/pencabutan')], 'fixed' => ['grant_versions' => $versions], 'subject_id' => null, 'expected_id' => null, 'expected_version' => null];

        return [...$page->toArray(), 'organization_id' => $org, 'section' => 'operators', 'can_manage' => true, 'filter_stores' => [], 'filter_materials' => [], 'forms' => [$form], 'data' => $rows];
    }

    private function summary(string $section, array $r): string
    {
        $lines = [];
        foreach (['code' => 'Kode', 'name' => 'Nama', 'title' => 'Resep', 'number' => 'Nomor', 'status' => 'Status', 'version' => 'Versi', 'unit' => 'Satuan', 'batch_code' => 'Lot / batch', 'received_on' => 'Diterima', 'expires_on' => 'Kedaluwarsa', 'due_on' => 'Jatuh tempo', 'total_amount' => 'Total (Rp)', 'received_amount' => 'Diterima dari pembelian (Rp)', 'paid_amount' => 'Dibayar (Rp)', 'credited_amount' => 'Kredit supplier (Rp)', 'refunded_amount' => 'Refund dari supplier (Rp)', 'output_packs' => 'Target kemasan per proses', 'runs' => 'Jumlah proses', 'good_packs' => 'Hasil baik (kemasan)', 'failed_pieces' => 'Hasil gagal (biji)', 'contact' => 'Kontak', 'address' => 'Alamat', 'note' => 'Catatan', 'reason' => 'Alasan', 'inspection_note' => 'Hasil pemeriksaan', 'evidence' => 'Bukti'] as $key => $label) {
            if (isset($r[$key])) {
                $lines[] = $label.': '.$r[$key];
            }
        }
        if ($section === 'materials') {
            $lines[] = 'Batas minimum: '.Quantity::display($r['minimum_quantity'], $r['unit']).' '.$r['unit'].'; wajib kedaluwarsa: '.($r['expiry_required'] ? 'ya' : 'tidak');
        }
        if ($section === 'purchases') {
            $balance = $r['received_amount'] - $r['credited_amount'] - $r['paid_amount'] + $r['refunded_amount'];
            $lines[] = 'Utang Rp'.max(0, $balance).'; piutang refund supplier Rp'.max(0, -$balance).'; lewat jatuh tempo: '.($balance > 0 && $r['due_on'] < now('Asia/Jakarta')->format('Y-m-d') ? 'ya' : 'tidak');
        }
        if ($section === 'lots') {
            $lines[] = 'Bahan: '.$r['material_name'];
            foreach (['usable' => 'Lolos pemeriksaan', 'quarantined' => 'Karantina', 'waste' => 'Rusak'] as $key => $label) {
                $lines[] = $label.': '.Quantity::display($r[$key.'_quantity'], $r['unit']).' '.$r['unit'];
            }
            $lines[] = 'Nilai tersisa: Rp'.$r['remaining_value'].'; diblokir: '.($r['blocked'] ? 'ya' : 'tidak');
        }
        if ($section === 'ledger') {
            $lot = MaterialLot::whereKey($r['material_lot_id'])->firstOrFail();
            $lines[] = $lot->material_name.' · '.$lot->batch_code.' · '.$r['bucket'].': '.($r['quantity_delta'] < 0 ? '-' : '+').Quantity::display(abs($r['quantity_delta']), $lot->unit).' '.$lot->unit.'; nilai Rp'.$r['value_delta'].'; '.$r['source_type'].' · '.$r['created_at'];
        }
        foreach ($r['ingredients'] ?? $r['recipe_snapshot']['ingredients'] ?? [] as $i) {
            $lines[] = 'Resep: '.$i['name'].' '.Quantity::display($i['quantity'], $i['unit']).' '.$i['unit'];
        }
        foreach ($r['lines'] ?? [] as $i) {
            $lines[] = 'Pembelian: '.$i['name'].' '.Quantity::display($i['quantity'], $i['unit']).' '.$i['unit'].'; diterima '.Quantity::display($i['received_quantity'], $i['unit']).'; total Rp'.$i['amount'];
        }
        foreach ($r['allocations'] ?? [] as $i) {
            $lines[] = 'Aktual: '.$i['name'].' · '.$i['batch_code'].' '.Quantity::display($i['quantity'], $i['unit']).' '.$i['unit'].' · Rp'.$i['value'];
        }
        foreach ($r['payments'] ?? [] as $p) {
            $lines[] = 'Pembayaran: '.$p['paid_on'].' · '.$p['method'].' · Rp'.$p['amount'].' · '.$p['reference'];
        }
        if (! empty($r['costs'])) {
            if (isset($r['good_quantity'])) {
                $lines[] = 'Hasil setengah jadi '.Quantity::display($r['good_quantity'], $r['recipe_snapshot']['yield_unit']).' '.$r['recipe_snapshot']['yield_unit'];
            }
            $lines[] = 'Biaya bahan Rp'.$r['costs']['goods_cost'].'; tenaga kerja Rp'.$r['costs']['labor_cost'].'; overhead Rp'.$r['costs']['overhead_cost'].'; total Rp'.$r['costs']['total_amount'].'; hasil '.($r['costs']['output_pieces'] ?? Quantity::display($r['costs']['output_quantity'], $r['costs']['output_unit'])).' '.($r['costs']['output_unit'] ?? 'pcs').'. Dasar: '.$r['costs']['evidence'];
        }

        if (isset($r['costs']['unit_cost_floor_idr'])) {
            $lines[] = 'HPP per biji dibulatkan ke bawah Rp'.$r['costs']['unit_cost_floor_idr'].'; sisa pembulatan biaya batch Rp'.$r['costs']['rounding_remainder_idr'];
        }

        return implode("\n", $lines);
    }

    private function projection(string $org, string $section, array $v, array $options): array
    {
        $rows = [];
        $store = $v['store_id'] ?? null;
        if ($section === 'planning') {
            $planned = $this->bounded(Production::where('organization_id', $org)->where('status', 'planned')->when($store, fn ($q) => $q->where('store_id', $store)));
            $need = [];
            foreach ($planned as $p) {
                foreach ($p['recipe_snapshot']['ingredients'] as $id => $i) {
                    $need[$id] = ($need[$id] ?? 0) + $i['quantity'] * $p['runs'];
                }
            }
            $incoming = [];
            foreach ($this->bounded(Purchase::where('organization_id', $org)->where('status', 'approved')->when($store, fn ($q) => $q->where('store_id', $store))) as $po) {
                foreach ($po['lines'] as $id => $line) {
                    $incoming[$id] = ($incoming[$id] ?? 0) + $line['quantity'] - $line['received_quantity'];
                }
            }
            foreach ($options['material_rows'] as $m) {
                if (isset($v['material_id']) && $m['id'] !== $v['material_id']) {
                    continue;
                }
                $available = 0;
                foreach ($options['lot_rows'] as $lot) {
                    if ($lot['material_id'] === $m['id'] && ! $lot['blocked'] && (! $lot['expires_on'] || substr($lot['expires_on'], 0, 10) >= now('Asia/Jakarta')->format('Y-m-d'))) {
                        $available += $lot['usable_quantity'];
                    }
                }
                $required = $need[$m['id']] ?? 0;
                $short = max(0, $required + $m['minimum_quantity'] - $available);
                $rows[] = ['id' => $m['id'], 'organization_id' => $org, 'summary' => $m['name'].' ('.$m['unit'].') · rencana '.Quantity::display($required, $m['unit']).' · layak pakai '.Quantity::display($available, $m['unit']).' · minimum '.Quantity::display($m['minimum_quantity'], $m['unit']).' · kekurangan stok saat ini '.Quantity::display($short, $m['unit']).' · pembelian belum diterima '.Quantity::display($incoming[$m['id']] ?? 0, $m['unit']).' · kebutuhan pembelian tambahan '.Quantity::display(max(0, $short - ($incoming[$m['id']] ?? 0)), $m['unit']), 'forms' => []];
            }
        } elseif ($section === 'trace') {
            if (empty($v['lot_id'])) {
                return ['data' => [], 'notice' => 'Pilih lot bahan dari riwayat bahan untuk melihat jejak produksi.'];
            }
            $root = MaterialLot::where('organization_id', $org)->findOrFail($v['lot_id']);
            $seen = [$root->id => true];
            $front = [$root->id];
            $found = [];
            for ($depth = 0; $front; $depth++) {
                abort_if($depth >= 100 || count($seen) > 1000 || count($found) > 1000, 422, 'Jejak melebihi batas.');
                $next = [];
                foreach ($this->bounded(MaterialLot::where('organization_id', $org)->whereIn('provenance->source_lot_id', $front)) as $child) {
                    if (! isset($seen[$child['id']])) {
                        $seen[$child['id']] = true;
                        $next[] = $child['id'];
                    }
                }
                $completed = $this->bounded(Production::where('organization_id', $org)->whereIn('id', DB::table('erp_material_entries')->where('organization_id', $org)->where('source_type', 'production_consumption')->whereIn('material_lot_id', $front)->select('source_id')));
                foreach ($completed as $p) {
                    if (! isset($found[$p['id']]) && collect($p['allocations'])->contains(fn ($a) => isset($seen[$a['lot_id']]))) {
                        $found[$p['id']] = $p;
                        if ($p['output_material_lot_id'] && ! isset($seen[$p['output_material_lot_id']])) {
                            $seen[$p['output_material_lot_id']] = true;
                            $next[] = $p['output_material_lot_id'];
                        }
                    }
                }
                $front = $next;
            }
            abort_if(count($found) > 1000 || count($seen) > 1000, 422, 'Jejak melebihi batas; tidak mengembalikan hasil parsial.');
            foreach ($found as $p) {
                $rows[] = [...$p, 'summary' => $this->summary('productions', $p), 'forms' => []];
            }
        } else {
            if (empty($v['from']) || empty($v['to'])) {
                return ['data' => [], 'notice' => 'Isi tanggal awal dan akhir laporan (WIB, maksimal31hari).'];
            }
            $from = CarbonImmutable::parse($v['from'].'T00:00:00+07:00');
            $to = CarbonImmutable::parse($v['to'].'T00:00:00+07:00')->addDay();
            abort_if($to->diffInDays($from, true) > 31, 422, 'Laporan maksimal31hari.');
            $productions = $this->bounded(Production::where('organization_id', $org)->when($store, fn ($q) => $q->where('store_id', $store))->whereBetween('produced_on', [$v['from'], $v['to']]));
            foreach ($productions as $p) {
                $rows[] = [...$p, 'summary' => $this->summary('productions', $p), 'forms' => []];
            }
        }

        return ['data' => $rows, 'notice' => $section === 'planning' ? 'Kebutuhan dari rencana produksi dan minimum eksplisit. Belum reservasi stok; tidak otomatis membuat pembelian.' : ($section === 'trace' ? 'Jejak bahan ke hasil produksi organisasi ini. Recall/penahanan lintas organisasi tetap mengikuti kewenangan penerima.' : 'HPP per proses dari pemakaian lot aktual dan biaya yang dinyatakan. Pembayaran supplier bukan biaya tambahan produksi; laporan ini bukan buku besar akuntansi.')];
    }
}
