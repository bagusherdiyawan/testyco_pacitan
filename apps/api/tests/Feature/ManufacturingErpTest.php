<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Manufacturing\Infrastructure\Production;
use App\Modules\Materials\Domain\Quantity;
use App\Modules\Materials\Infrastructure\Material;
use App\Modules\Materials\Infrastructure\MaterialLot;
use App\Modules\Procurement\Infrastructure\Purchase;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManufacturingErpTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Store $store;

    private User $owner;

    private User $reviewer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 9));
        $this->org = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        $this->store = Store::create(['organization_id' => $this->org->id, 'name' => 'Gudang produksi']);
        $this->owner = $this->user($this->org, 'owner');
        $this->reviewer = $this->user($this->org, 'admin');
        $this->product = Product::create(['organization_id' => $this->org->id, 'sku' => 'DIMSUM', 'name' => 'Dimsum ayam', 'condition' => 'frozen', 'pack_size' => 10, 'portion_size' => 1, 'wholesale_price' => 25000, 'retail_price' => 3000]);
        Sanctum::actingAs($this->owner);
    }

    private function user(Organization $org, string $role): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $u->id, 'role' => $role]);

        return $u;
    }

    private function path(string $action): string
    {
        return '/api/v1/organizations/'.$this->org->id.'/erp/'.$action;
    }

    private function erp(string $action, array $v): array
    {
        return $this->postJson($this->path($action), ['operation_id' => (string) Str::uuid(), ...$v])->assertCreated()->json('data');
    }

    private function material(string $code = 'FLOUR', string $unit = 'kg'): array
    {
        return $this->erp('material', ['code' => $code, 'name' => $code, 'unit' => $unit, 'minimum' => '0', 'expiry_required' => true, 'fefo_required' => false]);
    }

    private function lot(array $m, string $quantity = '2', int $value = 20000): array
    {
        return $this->erp('receive', ['source' => 'opening', 'store_id' => $this->store->id, 'material_id' => $m['id'], 'quantity' => $quantity, 'opening_value' => $value, 'batch_code' => 'LOT-'.$m['code'], 'received_on' => '2026-11-09', 'expires_on' => '2026-12-09', 'evidence' => 'Saldo awal terhitung', 'note' => 'Diperiksa']);
    }

    private function release(array $lot, string $quantity = '2'): array
    {
        return $this->erp('material_quality', ['id' => $lot['id'], 'version' => $lot['version'], 'decision' => 'release', 'quantity' => $quantity, 'note' => 'Bahan sesuai']);
    }

    private function recipe(array $ingredients): array
    {
        return $this->erp('recipe', ['product_id' => $this->product->id, 'title' => 'Resep dimsum', 'output_packs' => 5, 'ingredients' => $ingredients, 'instructions' => 'Bahan ditimbang, bentuk dan kemas']);
    }

    private function production(array $recipe, string $number = 'PRD-1'): array
    {
        return $this->erp('production', ['store_id' => $this->store->id, 'recipe_id' => $recipe['id'], 'recipe_versions' => [$recipe['id'] => $recipe['version']], 'number' => $number, 'runs' => 1, 'note' => 'Pesanan produksi']);
    }

    private function completion(array $p, array $allocations, array $revisions): array
    {
        return ['id' => $p['id'], 'version' => $p['version'], 'allocations' => $allocations, 'allocation_revisions' => $revisions, 'batch_code' => 'OUTPUT-'.$p['number'], 'produced_on' => '2026-11-09', 'expires_on' => '2026-12-01', 'good_packs' => 5, 'failed_pieces' => 2, 'labor_cost' => 3000, 'overhead_cost' => 2000, 'cost_allocation' => 'all_to_good_output', 'cost_evidence' => 'Bukti biaya produksi', 'note' => 'Hasil aktual ditimbang'];
    }

    public function test_raw_material_to_finished_product_is_atomic_costed_quarantined_and_released_only_after_qc(): void
    {
        $flour = $this->material();
        $egg = $this->material('EGG', 'pcs');
        $a = $this->release($this->lot($flour));
        $b = $this->release($this->lot($egg, '10', 10000), '10');
        $r = $this->recipe([$flour['id'] => '1', $egg['id'] => '2']);
        $p = $this->production($r);
        $body = ['operation_id' => (string) Str::uuid(), ...$this->completion($p, [$a['id'] => '1', $b['id'] => '2'], [$a['id'] => $a['version'], $b['id'] => $b['version']])];
        $done = $this->postJson($this->path('complete'), $body)->assertCreated()->assertJsonPath('data.status', 'awaiting_quality')->assertJsonPath('data.costs.goods_cost', 12000)->assertJsonPath('data.costs.total_amount', 17000)->json('data');
        $this->assertDatabaseHas('erp_material_lots', ['id' => $a['id'], 'usable_quantity' => 1000000, 'remaining_value' => 10000]);
        $this->assertDatabaseHas('erp_material_lots', ['id' => $b['id'], 'usable_quantity' => 8, 'remaining_value' => 8000]);
        $this->assertDatabaseHas('inventory_batches', ['id' => $done['output_batch_id'], 'usable_pieces' => 0, 'quarantined_pieces' => 50, 'stock_revision' => 1]);
        $this->postJson($this->path('complete'), $body)->assertCreated()->assertJsonPath('data.id', $p['id']);
        $this->assertDatabaseCount('erp_material_entries', 8);
        $released = $this->erp('production_quality', ['id' => $p['id'], 'version' => 2, 'stock_revision' => 1, 'decision' => 'release', 'note' => 'Owner memeriksa hasil']);
        $this->assertSame('released', $released['status']);
        $this->assertDatabaseHas('inventory_batches', ['id' => $done['output_batch_id'], 'usable_pieces' => 50, 'quarantined_pieces' => 0, 'stock_revision' => 2]);
        $this->getJson($this->path('context').'?section=trace&lot_id='.$a['id'])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $p['id']);
    }

    public function test_missing_or_insufficient_material_rolls_back_all_consumption_and_output(): void
    {
        $a = $this->material();
        $b = $this->material('MEAT');
        $la = $this->release($this->lot($a));
        $lb = $this->release($this->lot($b));
        $p = $this->production($this->recipe([$a['id'] => '1', $b['id'] => '1']));
        $v = $this->completion($p, [$la['id'] => '1', $lb['id'] => '9'], [$la['id'] => $la['version'], $lb['id'] => $lb['version']]);
        $this->postJson($this->path('complete'), ['operation_id' => (string) Str::uuid(), ...$v])->assertStatus(409);
        $this->assertDatabaseHas('erp_material_lots', ['id' => $la['id'], 'usable_quantity' => 2000000, 'remaining_value' => 20000]);
        $this->assertDatabaseCount('inventory_batches', 0);
        $this->assertDatabaseCount('erp_material_entries', 6);
        unset($v['allocations'][$lb['id']]);
        $this->postJson($this->path('complete'), ['operation_id' => (string) Str::uuid(), ...$v])->assertUnprocessable();
        $this->assertDatabaseCount('erp_material_entries', 6);
    }

    public function test_stale_revision_uninspected_blocked_expired_or_foreign_lots_cannot_be_consumed(): void
    {
        $m = $this->material();
        $lot = $this->lot($m);
        $p = $this->production($this->recipe([$m['id'] => '1']));
        $v = $this->completion($p, [$lot['id'] => '1'], [$lot['id'] => 1]);
        $this->postJson($this->path('complete'), ['operation_id' => (string) Str::uuid(), ...$v])->assertStatus(409);
        $lot = $this->release($lot);
        $this->postJson($this->path('complete'), ['operation_id' => (string) Str::uuid(), ...$v])->assertStatus(409);
        $held = $this->erp('material_quality', ['id' => $lot['id'], 'version' => 2, 'decision' => 'hold', 'note' => 'Investigasi bahan']);
        $v['allocation_revisions'][$lot['id']] = $held['version'];
        $this->postJson($this->path('complete'), ['operation_id' => (string) Str::uuid(), ...$v])->assertStatus(409);
        $this->assertDatabaseCount('inventory_batches', 0);
    }

    public function test_roles_cross_tenant_central_onboarding_and_inactive_store_are_denied(): void
    {
        foreach (['cashier', 'warehouse', 'store_manager'] as $role) {
            Sanctum::actingAs($this->user($this->org, $role));
            $this->getJson($this->path('context'))->assertForbidden();
            $this->postJson($this->path('supplier'), ['operation_id' => (string) Str::uuid(), 'name' => 'Supplier', 'contact' => 'Kontak', 'address' => 'Alamat'])->assertForbidden();
        }
        $other = Organization::create(['name' => 'Other', 'type' => 'center']);
        Sanctum::actingAs($this->user($other, 'owner'));
        $this->getJson($this->path('context'))->assertForbidden();
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/organizations/'.$other->id.'/erp/context')->assertForbidden();
        $m = $this->material();
        $this->store->update(['active' => false]);
        $this->postJson($this->path('receive'), ['operation_id' => (string) Str::uuid(), 'source' => 'opening', 'store_id' => $this->store->id, 'material_id' => $m['id'], 'quantity' => '1', 'opening_value' => 0, 'batch_code' => 'A', 'received_on' => '2026-11-09', 'expires_on' => '2026-12-01', 'evidence' => 'Evidence', 'note' => 'Note'])->assertStatus(409);
    }

    public function test_purchase_approval_partial_receipt_exact_rounding_and_verified_supplier_payment(): void
    {
        $m = $this->material();
        $supplier = $this->erp('supplier', ['name' => 'Supplier ayam', 'contact' => 'Kontak', 'address' => 'Alamat']);
        $p = $this->erp('purchase', ['store_id' => $this->store->id, 'supplier_id' => $supplier['id'], 'number' => 'PO-1', 'ordered_on' => '2026-11-09', 'due_on' => '2026-12-09', 'quantities' => [$m['id'] => '3'], 'amounts' => [$m['id'] => 10000], 'note' => 'Harga disepakati']);
        $p = $this->erp('purchase_decide', ['id' => $p['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Disetujui']);
        foreach ([3333, 3333, 3334] as $i => $value) {
            $lot = $this->erp('receive', ['source' => 'purchase', 'purchase_id' => $p['id'], 'store_id' => $this->store->id, 'material_id' => $m['id'], 'version' => $i + 2, 'quantity' => '1', 'batch_code' => 'POLOT-'.$i, 'received_on' => '2026-11-09', 'expires_on' => '2026-12-09', 'evidence' => 'Surat jalan', 'note' => 'Timbang aktual']);
            $this->assertSame($value, $lot['remaining_value']);
        }
        $paid = $this->erp('payment', ['id' => $p['id'], 'version' => 5, 'amount' => 9000, 'method' => 'bank_transfer', 'reference' => 'BANK-1', 'paid_on' => '2026-11-09', 'verified' => true, 'note' => 'Bukti diperiksa']);
        $this->assertSame(9000, $paid['paid_amount']);
        $this->postJson($this->path('payment'), ['operation_id' => (string) Str::uuid(), 'id' => $p['id'], 'version' => 6, 'amount' => 2000, 'method' => 'cash', 'reference' => 'CASH', 'paid_on' => '2026-11-09', 'verified' => true, 'note' => 'Bukti'])->assertStatus(409);
    }

    public function test_recipe_snapshots_and_plan_revisions_do_not_change_when_new_version_is_added(): void
    {
        $m = $this->material();
        $r = $this->recipe([$m['id'] => '1']);
        $p = $this->production($r);
        $r2 = $this->recipe([$m['id'] => '2']);
        $this->assertSame(2, $r2['version']);
        $this->assertSame(1000000, Production::findOrFail($p['id'])->recipe_snapshot['ingredients'][$m['id']]['quantity']);
        $this->getJson($this->path('context').'?section=planning')->assertOk()->assertJsonPath('data.0.organization_id', $this->org->id);
    }

    public function test_count_requires_independent_reviewer_and_does_not_silently_advance_revision(): void
    {
        $lot = $this->release($this->lot($this->material()));
        $count = $this->erp('count', ['id' => $lot['id'], 'version' => 2, 'bucket' => 'usable', 'quantity' => '1.5', 'adjustment_value' => 5000, 'note' => 'Hitungan fisik']);
        $v = ['operation_id' => (string) Str::uuid(), 'id' => $count['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Periksa independen'];
        $this->postJson($this->path('count_decide'), $v)->assertForbidden();
        Sanctum::actingAs($this->reviewer);
        $this->postJson($this->path('count_decide'), $v)->assertCreated();
        $this->assertDatabaseHas('erp_material_lots', ['id' => $lot['id'], 'usable_quantity' => 1500000, 'remaining_value' => 15000]);
    }

    public function test_transfer_preserves_cost_and_requires_receiver_inspection_without_creating_inventory_products(): void
    {
        $lot = $this->release($this->lot($this->material()));
        $target = Store::create(['organization_id' => $this->org->id, 'name' => 'Gudang dua']);
        $move = $this->erp('transfer', ['id' => $lot['id'], 'version' => 2, 'store_id' => $target->id, 'quantity' => '0.5', 'note' => 'Serah terima bahan']);
        $this->assertSame(500000, $move['quarantined_quantity']);
        $this->assertSame(5000, $move['remaining_value']);
        $this->assertSame($lot['id'], $move['provenance']['source_lot_id']);
        $this->assertDatabaseCount('products', 1);
    }

    public function test_quantity_is_exact_and_fractional_pieces_and_overflow_are_rejected(): void
    {
        $this->assertSame(1000000, Quantity::parse('1', 'kg'));
        $this->assertSame(1000000, Quantity::parse('1000', 'g'));
        $this->assertSame('1.25', Quantity::display(1250000, 'kg'));
        $this->assertSame(1234, Quantity::parse('1.234', 'g'));
        $this->postJson($this->path('material'), ['operation_id' => (string) Str::uuid(), 'code' => 'EGG', 'name' => 'Egg', 'unit' => 'pcs', 'minimum' => '0.5', 'expiry_required' => true, 'fefo_required' => false])->assertUnprocessable();
    }

    public function test_replay_cannot_change_payload_and_get_does_not_write_ledger(): void
    {
        $key = (string) Str::uuid();
        $v = ['operation_id' => $key, 'code' => 'FLOUR', 'name' => 'Flour', 'unit' => 'kg', 'minimum' => '0', 'expiry_required' => true, 'fefo_required' => false];
        $first = $this->postJson($this->path('material'), $v)->assertCreated()->json('data');
        $this->postJson($this->path('material'), $v)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $v['name'] = 'Changed';
        $this->postJson($this->path('material'), $v)->assertStatus(409);
        $this->getJson($this->path('context'))->assertOk()->assertJsonPath('organization_id', $this->org->id)->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('erp_material_entries', 0);
    }

    public function test_intermediate_dough_is_costed_quarantined_then_consumed_in_next_stage_with_transitive_trace(): void
    {
        $flour = $this->material();
        $dough = $this->material('DOUGH');
        $lot = $this->release($this->lot($flour));
        $r = $this->erp('recipe', ['output_material_id' => $dough['id'], 'output_quantity' => '1', 'title' => 'Adonan tahap1', 'ingredients' => [$flour['id'] => '1'], 'instructions' => 'Adonan ditimbang']);
        $p = $this->production($r, 'DOUGH-1');
        $v = $this->completion($p, [$lot['id'] => '1'], [$lot['id'] => $lot['version']]);
        $v['good_quantity'] = '1';
        $v['failed_quantity'] = '0';
        $done = $this->erp('complete', $v);
        $this->assertSame(1000000, $done['good_quantity']);
        $this->assertNotNull($done['output_material_lot_id']);
        $this->assertDatabaseHas('erp_material_lots', ['id' => $done['output_material_lot_id'], 'remaining_value' => 15000, 'quarantined_quantity' => 1000000, 'usable_quantity' => 0]);
        $this->postJson($this->path('material_quality'), ['operation_id' => (string) Str::uuid(), 'id' => $done['output_material_lot_id'], 'version' => 1, 'decision' => 'release', 'quantity' => '1', 'note' => 'Tidak boleh lewat jalur bahan biasa'])->assertStatus(409);
        $this->erp('production_quality', ['id' => $p['id'], 'version' => 2, 'stock_revision' => 1, 'decision' => 'release', 'note' => 'Owner memeriksa adonan']);
        $r2 = $this->recipe([$dough['id'] => '1']);
        $p2 = $this->production($r2, 'FINAL-1');
        $done2 = $this->erp('complete', $this->completion($p2, [$done['output_material_lot_id'] => '1'], [$done['output_material_lot_id'] => 2]));
        $this->assertSame(15000, $done2['costs']['goods_cost']);
        $this->assertSame(20000, $done2['costs']['total_amount']);
        $this->getJson($this->path('context').'?section=trace&lot_id='.$lot['id'])->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->path('context').'?section=productions')->assertOk()->assertJsonPath('data.0.forms.0.operation', 'production_quality');
    }

    public function test_expired_material_and_quality_rejection_never_create_sellable_finished_stock(): void
    {
        $m = $this->material();
        $lot = $this->release($this->lot($m));
        $p = $this->production($this->recipe([$m['id'] => '1']));
        MaterialLot::whereKey($lot['id'])->update(['expires_on' => '2026-11-08']);
        $v = $this->completion($p, [$lot['id'] => '1'], [$lot['id'] => $lot['version']]);
        $this->postJson($this->path('complete'), ['operation_id' => (string) Str::uuid(), ...$v])->assertStatus(409);
        MaterialLot::whereKey($lot['id'])->update(['expires_on' => '2026-12-09']);
        $done = $this->erp('complete', $v);
        $this->erp('production_quality', ['id' => $p['id'], 'version' => 2, 'stock_revision' => 1, 'decision' => 'reject', 'note' => 'Hasil tidak lolos pemeriksaan']);
        $this->assertDatabaseHas('inventory_batches', ['id' => $done['output_batch_id'], 'usable_pieces' => 0, 'quarantined_pieces' => 0, 'waste_pieces' => 50]);
    }

    public function test_supplier_return_reduces_only_verified_unpaid_purchase_balance(): void
    {
        $m = $this->material();
        $supplier = $this->erp('supplier', ['name' => 'Supplier', 'contact' => 'Kontak', 'address' => 'Alamat']);
        $p = $this->erp('purchase', ['store_id' => $this->store->id, 'supplier_id' => $supplier['id'], 'number' => 'PO-RETURN', 'ordered_on' => '2026-11-09', 'due_on' => '2026-12-09', 'quantities' => [$m['id'] => '2'], 'amounts' => [$m['id'] => 20000], 'note' => 'Disepakati']);
        $this->erp('purchase_decide', ['id' => $p['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Disetujui']);
        $lot = $this->erp('receive', ['source' => 'purchase', 'purchase_id' => $p['id'], 'store_id' => $this->store->id, 'material_id' => $m['id'], 'version' => 2, 'quantity' => '2', 'batch_code' => 'RETURN-LOT', 'received_on' => '2026-11-09', 'expires_on' => '2026-12-09', 'evidence' => 'Surat jalan', 'note' => 'Penerimaan']);
        $this->erp('payment', ['id' => $p['id'], 'version' => 3, 'amount' => 20000, 'method' => 'bank_transfer', 'reference' => 'PAID-SUPPLIER', 'paid_on' => '2026-11-09', 'verified' => true, 'note' => 'Bukti diperiksa']);
        $this->postJson($this->path('material_quality'), ['operation_id' => (string) Str::uuid(), 'id' => $lot['id'], 'version' => 1, 'decision' => 'return', 'bucket' => 'quarantined', 'quantity' => '1', 'supplier_credit' => 20000, 'verified' => true, 'note' => 'Over historical cap'])->assertUnprocessable();
        $this->erp('material_quality', ['id' => $lot['id'], 'version' => 1, 'decision' => 'return', 'bucket' => 'quarantined', 'quantity' => '1', 'supplier_credit' => 10000, 'verified' => true, 'note' => 'Retur fisik, kredit supplier dikonfirmasi']);
        $this->assertSame(10000, Purchase::findOrFail($p['id'])->credited_amount);
        $refund = $this->erp('payment', ['id' => $p['id'], 'version' => 5, 'kind' => 'supplier_refund', 'amount' => 10000, 'method' => 'bank_transfer', 'reference' => 'SUPPLIER-REFUND', 'paid_on' => '2026-11-09', 'verified' => true, 'note' => 'Refund supplier diterima']);
        $this->assertSame(10000, $refund['refunded_amount']);
        $this->assertDatabaseHas('erp_material_lots', ['id' => $lot['id'], 'quarantined_quantity' => 1000000, 'remaining_value' => 10000]);
    }

    public function test_explicit_fefo_policy_blocks_later_lot_and_configuration_preserves_unit(): void
    {
        $m = $this->material();
        $this->erp('material_configure', ['id' => $m['id'], 'version' => 1, 'name' => 'Terigu', 'minimum' => '0.5', 'expiry_required' => true, 'fefo_required' => true, 'active' => true]);
        $earlier = $this->release($this->lot($m));
        $later = $this->release($this->lot($m));
        MaterialLot::whereKey($earlier['id'])->update(['expires_on' => '2026-11-20']);
        $p = $this->production($this->recipe([$m['id'] => '1']));
        $v = $this->completion($p, [$later['id'] => '1'], [$later['id'] => $later['version']]);
        $this->postJson($this->path('complete'), ['operation_id' => (string) Str::uuid(), ...$v])->assertStatus(409);
        $v = $this->completion($p, [$later['id'] => '1', $earlier['id'] => '2'], [$later['id'] => $later['version'], $earlier['id'] => $earlier['version']]);
        $this->erp('complete', $v);
        $this->assertDatabaseHas('erp_material_lots', ['id' => $earlier['id'], 'usable_quantity' => 0]);
    }

    public function test_foreign_material_store_recipe_and_lot_references_are_denied_without_stock_changes(): void
    {
        $other = Organization::create(['name' => 'Foreign center', 'type' => 'center']);
        $otherStore = Store::create(['organization_id' => $other->id, 'name' => 'Foreign']);
        $otherOwner = $this->user($other, 'owner');
        $foreignMaterial = Material::create(['organization_id' => $other->id, 'code' => 'PRIVATE', 'name' => 'Private', 'unit' => 'kg', 'minimum_quantity' => 0, 'expiry_required' => true, 'fefo_required' => false, 'active' => true, 'version' => 1]);
        $m = $this->material();
        $lot = $this->release($this->lot($m));
        $p = $this->production($this->recipe([$m['id'] => '1']));
        $this->postJson($this->path('recipe'), ['operation_id' => (string) Str::uuid(), 'product_id' => $this->product->id, 'title' => 'Foreign ingredient', 'output_packs' => 1, 'ingredients' => [$foreignMaterial->id => '1'], 'instructions' => 'No'])->assertNotFound();
        $this->getJson($this->path('context').'?store_id='.$otherStore->id)->assertForbidden();
        $this->getJson($this->path('context').'?material_id='.$foreignMaterial->id)->assertForbidden();
        Sanctum::actingAs($otherOwner);
        $foreignPath = '/api/v1/organizations/'.$other->id.'/erp/complete';
        $this->postJson($foreignPath, ['operation_id' => (string) Str::uuid(), ...$this->completion($p, [$lot['id'] => '1'], [$lot['id'] => 2])])->assertNotFound();
        $this->assertDatabaseHas('erp_material_lots', ['id' => $lot['id'], 'usable_quantity' => 2000000]);
        $this->assertDatabaseCount('inventory_batches', 0);
    }

    public function test_zero_material_stock_cannot_leave_an_orphaned_book_value(): void
    {
        $lot = $this->release($this->lot($this->material()));
        $c = $this->erp('count', ['id' => $lot['id'], 'version' => 2, 'bucket' => 'usable', 'quantity' => '0', 'adjustment_value' => 0, 'note' => 'Hitung fisik nihil']);
        Sanctum::actingAs($this->reviewer);
        $this->postJson($this->path('count_decide'), ['operation_id' => (string) Str::uuid(), 'id' => $c['id'], 'version' => 1, 'decision' => 'approve', 'note' => 'Nilai koreksi belum benar'])->assertStatus(409);
        $this->assertDatabaseHas('erp_material_lots', ['id' => $lot['id'], 'usable_quantity' => 2000000, 'remaining_value' => 20000, 'stock_revision' => 2]);
    }

    public function test_receipt_calendar_uses_wib_today_instead_of_server_utc_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-08T18:00:00Z'));
        $m = $this->material();
        $lot = $this->lot($m);
        $this->assertSame('2026-11-09', $lot['received_on']);
        $this->postJson($this->path('receive'), ['operation_id' => (string) Str::uuid(), 'source' => 'opening', 'store_id' => $this->store->id, 'material_id' => $m['id'], 'quantity' => '1', 'opening_value' => 0, 'batch_code' => 'FUTURE', 'received_on' => '2026-11-10', 'expires_on' => '2026-12-09', 'evidence' => 'Evidence', 'note' => 'Future is invalid'])->assertUnprocessable();
    }
}
