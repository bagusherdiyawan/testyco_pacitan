<?php

namespace App\Modules\Manufacturing\Application;

use App\Models\User;
use App\Modules\Accounting\Application\AutomaticPosting;
use App\Modules\Catalog\Application\BundlePricing;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Costing\Application\ProductionCosts;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Manufacturing\Infrastructure\Production;
use App\Modules\Manufacturing\Infrastructure\Recipe;
use App\Modules\Materials\Application\MaterialLedger;
use App\Modules\Materials\Domain\Quantity;
use App\Modules\Materials\Infrastructure\Material;
use App\Modules\Materials\Infrastructure\MaterialLot;
use App\Shared\Domain\Rupiah;

final class ProductionWorkflow
{
    public function __construct(private ErpAccess $access, private MaterialLedger $materials, private StockLedger $stock) {}

    public function recipe(string $org, array $v): array
    {
        abort_unless((bool) ($v['product_id'] ?? null) !== (bool) ($v['output_material_id'] ?? null), 422, 'Pilih tepat satu hasil: produk jadi atau bahan setengah jadi.');
        $semi = ! empty($v['output_material_id']);
        $p = $material = null;
        if ($semi) {
            $material = Material::where('organization_id', $org)->where('active', true)->lockForUpdate()->findOrFail($v['output_material_id']);
            $yield = Quantity::parse($v['output_quantity'] ?? null, $material->unit);
        } else {
            $p = Product::where('organization_id', $org)->where('active', true)->lockForUpdate()->findOrFail($v['product_id']);
            abort_if(BundlePricing::recipe($p->id), 422, 'Paket POS bukan hasil produksi fisik.');
        }
        $ingredients = [];
        foreach ($v['ingredients'] as $id => $input) {
            if ($input === null || $input === '') {
                continue;
            }
            $m = Material::where('organization_id', $org)->where('active', true)->findOrFail($id);
            abort_if($semi && $id === $material->id, 422, 'Bahan hasil tidak boleh sekaligus menjadi bahan resep sendiri.');
            $ingredients[$id] = ['material_id' => $id, 'name' => $m->name, 'unit' => $m->unit, 'quantity' => Quantity::parse($input, $m->unit)];
        }
        abort_unless(count($ingredients) > 0, 422, 'Resep wajib mempunyai bahan.');

        $versions = Recipe::where('organization_id', $org)->where($semi ? 'output_material_id' : 'product_id', $semi ? $material->id : $p->id);

        return Recipe::create(['organization_id' => $org, 'product_id' => $p?->id, 'output_material_id' => $material?->id, 'output_kind' => $semi ? 'material' : 'product', 'yield_quantity' => $semi ? $yield : null, 'yield_unit' => $material?->unit, 'material_snapshot' => $material?->only(['id', 'name', 'unit']), 'title' => $v['title'], 'output_packs' => $semi ? null : $v['output_packs'], 'ingredients' => $ingredients, 'product_snapshot' => $p?->only(['id', 'sku', 'name', 'condition', 'pack_size']), 'instructions' => $v['instructions'], 'active' => true, 'version' => ($versions->max('version') ?? 0) + 1])->toArray();
    }

    public function create(User $u, string $org, array $v): array
    {
        abort_if(Production::where('organization_id', $org)->where('number', $v['number'])->exists(), 409, 'Nomor produksi sudah digunakan.');
        $this->access->store($u, $org, $v['store_id']);
        $r = Recipe::where('organization_id', $org)->where('active', true)->lockForUpdate()->findOrFail($v['recipe_id']);
        abort_unless($r->version === ($v['recipe_versions'][$r->id] ?? null), 409, 'Resep berubah.');
        abort_if($r->output_kind === 'product' && $v['runs'] * $r->output_packs > 1000000, 422, 'Rencana hasil melebihi batas.');
        abort_if($r->output_kind === 'material' && $r->yield_quantity > intdiv(1000000000000, $v['runs']), 422, 'Rencana hasil melebihi batas.');

        return Production::create(['organization_id' => $org, 'store_id' => $v['store_id'], 'recipe_id' => $r->id, 'number' => $v['number'], 'runs' => $v['runs'], 'recipe_snapshot' => $r->toArray(), 'allocations' => [], 'costs' => [], 'status' => 'planned', 'version' => 1, 'created_by' => $u->id, 'note' => $v['note']])->toArray();
    }

    public function cancel(string $org, array $v): array
    {
        $p = Production::where('organization_id', $org)->lockForUpdate()->findOrFail($v['id']);
        abort_unless($p->version === $v['version'] && $p->status === 'planned', 409);
        $p->status = 'cancelled';
        $p->note = $v['note'];
        $p->version++;
        $p->save();

        return $p->toArray();
    }

    public function complete(User $u, string $org, array $v): array
    {
        $p = Production::where('organization_id', $org)->lockForUpdate()->findOrFail($v['id']);
        abort_unless($p->version === $v['version'] && $p->status === 'planned', 409, 'Produksi sudah diproses/versi berubah.');
        $this->access->store($u, $org, $p->store_id);
        Store::lockForUpdate()->findOrFail($p->store_id);
        $recipe = $p->recipe_snapshot;
        $semi = $recipe['output_kind'] === 'material';
        $snapshot = $recipe['product_snapshot'];
        $goodQuantity = $failedQuantity = null;
        if ($semi) {
            $material = Material::where('organization_id', $org)->where('active', true)->lockForUpdate()->findOrFail($recipe['output_material_id']);
            abort_unless($material->unit === $recipe['yield_unit'], 409, 'Satuan hasil berubah.');
            $goodQuantity = Quantity::parse($v['good_quantity'] ?? null, $material->unit, true);
            $failedQuantity = Quantity::parse($v['failed_quantity'] ?? null, $material->unit, true);
            $hasOutput = $goodQuantity > 0;
            $hasFailure = $failedQuantity > 0;
        } else {
            $product = Product::where('organization_id', $org)->where('active', true)->lockForUpdate()->findOrFail($snapshot['id']);
            abort_unless($product->pack_size === $snapshot['pack_size'] && $product->condition === $snapshot['condition'] && ! BundlePricing::recipe($product->id), 409, 'Konversi/kondisi produk berubah. Buat rencana baru.');
            abort_unless(isset($v['good_packs'],$v['failed_pieces']), 422, 'Isi hasil baik dan gagal.');
            $hasOutput = $v['good_packs'] > 0;
            $hasFailure = $v['failed_pieces'] > 0;
            abort_if(InventoryBatch::where('store_id', $p->store_id)->where('product_id', $product->id)->where('batch_code', $v['batch_code'])->where('pack_size', $snapshot['pack_size'])->exists(), 409, 'Kode batch hasil sudah digunakan.');
        }
        abort_unless($v['expires_on'] >= $v['produced_on'] && ($hasOutput || $hasFailure), 422, 'Tanggal/hasil produksi tidak valid.');
        $allocations = [];
        $used = [];
        $goods = 0;
        $inputs = $v['allocations'];
        $ids = array_keys(array_filter($inputs, fn ($x) => $x !== null && $x !== ''));
        $selected = MaterialLot::where('organization_id', $org)->where('store_id', $p->store_id)->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($selected->count() === count($ids), 404, 'Lot bahan tidak ditemukan dalam gudang ini.');
        usort($ids, fn ($a, $b) => strcmp(($selected[$a]->expires_on?->format('Y-m-d') ?? '9999-12-31').'|'.$a, ($selected[$b]->expires_on?->format('Y-m-d') ?? '9999-12-31').'|'.$b));
        $inputs = array_replace(array_flip($ids), $inputs);
        $inputs = array_intersect_key($inputs, array_flip($ids));
        foreach ($inputs as $id => $input) {
            if ($input === null || $input === '') {
                continue;
            }
            $lot = MaterialLot::where('organization_id', $org)->where('store_id', $p->store_id)->lockForUpdate()->findOrFail($id);
            abort_unless(isset($recipe['ingredients'][$lot->material_id]), 422, 'Bahan tidak tercantum dalam resep.');
            $this->materials->eligible($lot);
            abort_unless(Material::whereKey($lot->material_id)->where('active', true)->exists(), 409, 'Bahan nonaktif.');
            abort_unless($lot->stock_revision === ($v['allocation_revisions'][$id] ?? null), 409, 'Revisi bahan berubah. Tinjau ulang pemakaian.');
            abort_unless($lot->received_on->format('Y-m-d') <= $v['produced_on'], 422, 'Bahan diterima setelah tanggal produksi.');
            $m = Material::findOrFail($lot->material_id);
            if ($m->fefo_required) {
                $earlier = MaterialLot::where('organization_id', $org)->where('store_id', $p->store_id)->where('material_id', $m->id)->where('usable_quantity', '>', 0)->where('blocked', false)->whereNotNull('expires_on')->where('expires_on', '>=', now('Asia/Jakarta')->format('Y-m-d'));
                if ($lot->expires_on) {
                    $earlier->where(fn ($q) => $q->where('expires_on', '<', $lot->expires_on->format('Y-m-d'))->orWhere(fn ($q) => $q->whereDate('expires_on', $lot->expires_on->format('Y-m-d'))->where('id', '<', $lot->id)));
                }
                abort_if($earlier->where('id', '!=', $lot->id)->exists(), 409, 'FEFO: habiskan lot layak dengan kedaluwarsa lebih dekat dahulu.');
            }
            $quantity = Quantity::parse($input, $lot->unit);
            abort_unless($quantity <= $lot->usable_quantity, 409, 'Bahan belum lolos pemeriksaan atau stok tidak cukup.');
            $value = $this->materials->value($lot, $quantity);
            $goods = Rupiah::add($goods, $value);
            $allocations[] = ['lot_id' => $lot->id, 'material_id' => $lot->material_id, 'name' => $lot->material_name, 'batch_code' => $lot->batch_code, 'unit' => $lot->unit, 'quantity' => $quantity, 'value' => $value, 'revision' => $lot->stock_revision];
            $used[$lot->material_id] = true;
            $this->materials->apply($lot, $u->id, ['usable' => -$quantity], -$value, 'production_consumption', $p->id, $v['note']);
        }
        abort_unless(count($used) === count($recipe['ingredients']), 422, 'Catat pemakaian aktual seluruh bahan resep.');
        $operating = Rupiah::add($v['labor_cost'], $v['overhead_cost']);
        $total = Rupiah::add($goods, $operating);
        $batch = $outputLot = null;
        if ($hasOutput && ! $semi) {
            $batch = $this->stock->credit($org, $p->store_id, $snapshot, $v['batch_code'], $v['expires_on'], $v['good_packs'], $u->id, 'production_output', $p->id, 'quarantined', $v['note'], $total);
        }
        if ($hasOutput && $semi) {
            $outputLot = MaterialLot::create(['organization_id' => $org, 'store_id' => $p->store_id, 'material_id' => $material->id, 'material_name' => $material->name, 'unit' => $material->unit, 'batch_code' => $v['batch_code'], 'received_on' => $v['produced_on'], 'expires_on' => $v['expires_on'], 'provenance' => ['source' => 'production', 'production_id' => $p->id], 'evidence' => $v['cost_evidence'], 'usable_quantity' => 0, 'quarantined_quantity' => 0, 'waste_quantity' => 0, 'remaining_value' => 0, 'stock_revision' => 0, 'blocked' => false]);
            $outputLot = $this->materials->apply($outputLot, $u->id, ['quarantined' => $goodQuantity], $total, 'production_output', $p->id, $v['note']);
        }
        $p->fill(['status' => $hasOutput ? 'awaiting_quality' : 'failed', 'version' => $p->version + 1, 'batch_code' => $v['batch_code'], 'produced_on' => $v['produced_on'], 'expires_on' => $v['expires_on'], 'good_packs' => $semi ? null : $v['good_packs'], 'failed_pieces' => $semi ? null : $v['failed_pieces'], 'good_quantity' => $goodQuantity, 'failed_quantity' => $failedQuantity, 'output_batch_id' => $batch['id'] ?? null, 'output_material_lot_id' => $outputLot?->id, 'allocations' => $allocations, 'completed_by' => $u->id, 'costs' => app(ProductionCosts::class)->calculate($goods, $v['labor_cost'], $v['overhead_cost'], $v['cost_evidence'], $semi ? null : $v['good_packs'] * $snapshot['pack_size'], $goodQuantity, $semi ? $material->unit : 'pcs'), 'note' => $v['note']]);
        $p->save();
        app(AutomaticPosting::class)->record($org, $u->id, 'production_consumption', $p->id, $v['produced_on'], [['wip', 'raw', $goods]], $v['cost_evidence']);
        $destination = $hasOutput ? ($semi ? 'raw' : 'finished') : 'loss';
        app(AutomaticPosting::class)->record($org, $u->id, 'production_output', $p->id, $v['produced_on'], [[$destination, 'wip', $goods], [$destination, 'labor', $v['labor_cost']], [$destination, 'overhead', $v['overhead_cost']]], $v['cost_evidence']);

        return $p->toArray();
    }
}
