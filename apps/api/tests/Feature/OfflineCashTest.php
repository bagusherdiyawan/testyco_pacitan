<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfflineCashTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Store $store;

    private Product $product;

    private InventoryBatch $batch;

    private User $cashier;

    private User $manager;

    private User $other;

    private User $outside;

    private User $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Mitra A', 'type' => 'partner']);
        $this->store = Store::create(['organization_id' => $this->org->id, 'name' => 'A1', 'address' => 'Pacitan']);
        $otherOrg = Organization::create(['name' => 'Mitra B', 'type' => 'partner']);
        $this->cashier = $this->user($this->org, 'cashier', [$this->store]);
        $this->other = $this->user($this->org, 'cashier', [$this->store]);
        $this->manager = $this->user($this->org, 'partner_lead');
        $this->warehouse = $this->user($this->org, 'warehouse', [$this->store]);
        $this->outside = $this->user($otherOrg, 'partner_lead');
        $this->product = Product::create(['organization_id' => $this->org->id, 'sku' => 'AYAM10', 'name' => 'Ayam', 'condition' => 'frozen', 'pack_size' => 10, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 20000]);
        $this->batch = $this->seedStock($this->product);
    }

    private function user(Organization $org, string $role, array $stores = []): User
    {
        $u = User::factory()->create(['active' => true]);
        DB::table('organization_user')->insert(['organization_id' => $org->id, 'user_id' => $u->id, 'role' => $role]);
        foreach ($stores as $s) {
            DB::table('store_user')->insert(['user_id' => $u->id, 'store_id' => $s->id]);
        }

        return $u;
    }

    private function act(User $u): self
    {
        Sanctum::actingAs($u);

        return $this;
    }

    private function seedStock(Product $p): InventoryBatch
    {
        return DB::transaction(function () use ($p) {
            $ledger = app(StockLedger::class);
            $v = $ledger->credit($this->org->id, $this->store->id, $p->only(['id', 'sku', 'name', 'condition', 'pack_size']), 'LOT-'.$p->sku, '2030-01-01', 10, $this->manager->id, 'shipment_receipt', (string) Str::ulid(), 'usable', 'Receipt fixture');
            $b = InventoryBatch::find($v['id']);
            $ledger->apply($b, $this->manager->id, ['usable' => -40, 'opened' => 10, 'ready' => 30], 'inventory_cook', (string) Str::ulid(), 'Prepared fixture');

            return $b->fresh();
        });
    }

    private function open(?User $actor = null, int $cash = 100000): array
    {
        return $this->act($actor ?? $this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/shifts', ['operation_id' => (string) Str::uuid(), 'device_name' => 'Kasir 1', 'opening_cash' => $cash])->assertCreated()->json('data');
    }

    private function cart(array $shift, int $qty = 4, string $unit = 'piece', string $bucket = 'ready'): array
    {
        return ['operation_id' => (string) Str::uuid(), 'shift_id' => $shift['id'], 'lines' => [['product_id' => $this->product->id, 'unit' => $unit, 'quantity' => $qty, 'allocations' => [['batch_id' => $this->batch->id, 'bucket' => $bucket, 'quantity' => $qty]]]]];
    }

    private function intent(array $shift, int $qty = 4, string $unit = 'piece', string $bucket = 'ready', ?User $actor = null): array
    {
        return $this->act($actor ?? $this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $this->cart($shift, $qty, $unit, $bucket))->assertCreated()->json('data');
    }

    private function pay(array $intent, int $cash = 20000, ?User $actor = null): array
    {
        return $this->act($actor ?? $this->cashier)->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => $cash])->assertOk()->json('data');
    }

    private function permit(array $shift): array
    {
        return $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/offline/permits', ['operation_id' => (string) Str::uuid(), 'shift_id' => $shift['id'], 'duration_minutes' => 60])->assertCreated()->json('data');
    }

    private function capture(array $p): array
    {
        return ['operation_id' => (string) Str::uuid(), 'permit_id' => $p['id'], 'captured_at' => now()->toIso8601String(), 'client_total' => 12000, 'cash_received' => 20000, 'lines' => $this->cart(['id' => $p['cash_shift_id']])['lines']];
    }

    public function test_cash_offline_sync_once_snapshot_and_explicit_end_unblocks_shift(): void
    {
        $shift = $this->open();
        $p = $this->permit($shift);
        $this->assertSame(['cash'], $p['snapshot']['payment_methods']);
        $this->assertArrayNotHasKey('wholesale_price', $p['snapshot']['products'][0]);
        $this->act($this->cashier)->postJson('/api/v1/shifts/'.$shift['id'].'/close', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'counted_cash' => 100000])->assertConflict();
        $url = '/api/v1/stores/'.$this->store->id.'/sales/offline';
        $body = $this->capture($p);
        $r = $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.status', 'accepted')->json('data');
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.id', $r['id']);
        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(26, $this->batch->fresh()->ready_pieces);
        $this->assertDatabaseHas('cash_shifts', ['id' => $shift['id'], 'cash_sales' => 12000, 'expected_cash' => 112000]);
        $this->getJson('/api/v1/sales/offline/'.$r['id'])->assertOk()->assertJsonPath('data.receipt.cash_received', 20000);
        $this->postJson('/api/v1/sales/offline/permits/'.$p['id'].'/end', ['operation_id' => (string) Str::uuid(), 'queue_empty' => true])->assertOk();
        $this->postJson('/api/v1/shifts/'.$shift['id'].'/close', ['operation_id' => (string) Str::uuid(), 'version' => 2, 'counted_cash' => 112000])->assertOk();
    }

    public function test_price_conflict_keeps_cash_evidence_then_manager_explicitly_accepts_frozen_quote(): void
    {
        $p = $this->permit($this->open());
        $b = $this->capture($p);
        $this->product->update(['retail_price' => 4000]);
        $r = $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/offline', $b)->assertCreated()->assertJsonPath('data.status', 'conflict')->json('data');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(30, $this->batch->fresh()->ready_pieces);
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'shift_id' => $p['cash_shift_id'], 'shift_version' => 1, 'verified' => true, 'reason' => 'Honor frozen quote, reconcile actual unbooked cash', 'reconciliation_reference' => 'Cash count signed'];
        $url = '/api/v1/sales/offline/'.$r['id'].'/accept';
        $this->postJson($url, $v)->assertForbidden();
        $accepted = $this->act($this->manager)->postJson($url, $v)->assertOk()->assertJsonPath('data.status', 'accepted')->json('data');
        $this->postJson($url, $v)->assertOk();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('sales', ['id' => $accepted['sale_id'], 'total_amount' => 12000]);
        $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/offline', $b)->assertCreated()->assertJsonPath('data.status', 'conflict');
        $this->getJson('/api/v1/sales/offline/'.$r['id'])->assertOk()->assertJsonPath('data.status', 'accepted');
    }

    public function test_short_stock_never_silently_posts_and_void_retains_payload_after_verified_refund(): void
    {
        $p = $this->permit($this->open());
        $b = $this->capture($p);
        $this->batch->update(['ready_pieces' => 0]);
        $r = $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/offline', $b)->assertCreated()->assertJsonPath('data.status', 'conflict')->json('data');
        $this->assertSame($b, $r['payload']);
        $this->act($this->manager)->postJson('/api/v1/sales/offline/'.$r['id'].'/void', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'verified' => true, 'reason' => 'Customer cash returned, no accounting duplicate', 'reconciliation_reference' => 'Signed refund evidence'])->assertOk()->assertJsonPath('data.status', 'voided');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('cash_shifts', ['id' => $p['cash_shift_id'], 'expected_cash' => 100000]);
    }

    public function test_foreign_scope_changed_identity_and_noncash_voucher_are_denied_or_conflict(): void
    {
        $p = $this->permit($this->open());
        $b = $this->capture($p);
        $url = '/api/v1/stores/'.$this->store->id.'/sales/offline';
        $this->act($this->outside)->postJson($url, $b)->assertForbidden();
        $this->act($this->other)->postJson($url, $b)->assertNotFound();
        $this->act($this->cashier)->postJson($url, [...$b, 'voucher_code' => 'FAKE'])->assertUnprocessable();
        $this->postJson($url, [...$b, 'payment_method' => 'qris_manual'])->assertUnprocessable();
        $this->cashier->increment('access_version');
        $this->act($this->cashier->fresh())->postJson($url, $b)->assertCreated()->assertJsonPath('data.status', 'conflict');
        $this->assertDatabaseCount('sales', 0);
    }
}
