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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Packaging and cutlery for booths: rules by service type, quantity ledger, purchases, never blocking a sale. */
class ConsumablesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private Store $store;

    private Store $otherStore;

    private Product $product;

    private InventoryBatch $batch;

    private User $lead;

    private User $manager;

    private User $cashier;

    private User $unassigned;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::create(['name' => 'Mitra A', 'type' => 'partner']);
        $this->otherOrg = Organization::create(['name' => 'Mitra B', 'type' => 'partner']);
        $this->store = Store::create(['organization_id' => $this->org->id, 'name' => 'Booth A1', 'address' => 'Pacitan']);
        $this->otherStore = Store::create(['organization_id' => $this->org->id, 'name' => 'Booth A2', 'address' => 'Pacitan']);
        $this->lead = $this->user($this->org, 'partner_lead');
        $this->manager = $this->user($this->org, 'store_manager', [$this->store]);
        $this->cashier = $this->user($this->org, 'cashier', [$this->store]);
        $this->unassigned = $this->user($this->org, 'cashier', [$this->otherStore]);
        $this->outsider = $this->user($this->otherOrg, 'partner_lead');
        $this->product = Product::create(['organization_id' => $this->org->id, 'sku' => 'AYAM10', 'name' => 'Ayam', 'condition' => 'frozen', 'pack_size' => 10, 'portion_size' => 4, 'retail_price' => 3000, 'wholesale_price' => 20000]);
        $this->batch = DB::transaction(function () {
            $ledger = app(StockLedger::class);
            $v = $ledger->credit($this->org->id, $this->store->id, $this->product->only(['id', 'sku', 'name', 'condition', 'pack_size']), 'LOT-1', '2030-01-01', 10, $this->lead->id, 'shipment_receipt', (string) Str::ulid(), 'usable', 'Fixture');
            $b = InventoryBatch::find($v['id']);
            $ledger->apply($b, $this->lead->id, ['usable' => -40, 'opened' => 10, 'ready' => 30], 'inventory_cook', (string) Str::ulid(), 'Fixture');

            return $b->fresh();
        });
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

    private function op(array $body = []): array
    {
        return ['operation_id' => (string) Str::uuid(), ...$body];
    }

    private function item(string $name, string $unit = 'pcs'): array
    {
        return $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->org->id.'/consumables/items', $this->op(['name' => $name, 'unit' => $unit]))->assertCreated()->json('data');
    }

    private function rule(array $item, string $type, string $basis, int $quantity): void
    {
        $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->org->id.'/consumables/rules', $this->op(['service_type' => $type, 'consumable_item_id' => $item['id'], 'basis' => $basis, 'quantity' => $quantity]))->assertOk();
    }

    private function buy(array $item, int $quantity, ?User $actor = null, array $extra = []): TestResponse
    {
        return $this->act($actor ?? $this->manager)->postJson('/api/v1/stores/'.$this->store->id.'/consumables/purchases', $this->op(['consumable_item_id' => $item['id'], 'quantity' => $quantity, ...$extra]));
    }

    private function balance(array $item): int
    {
        foreach ($this->act($this->cashier)->getJson('/api/v1/stores/'.$this->store->id.'/consumables')->assertOk()->json('data.items') as $row) {
            if ($row['id'] === $item['id']) {
                return $row['balance'];
            }
        }
        $this->fail('Item not listed');
    }

    private function openShift(): array
    {
        return $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/shifts', $this->op(['device_name' => 'Kasir 1', 'opening_cash' => 100000]))->assertCreated()->json('data');
    }

    private function sell(array $shift, int $portions, ?string $serviceType): array
    {
        $body = $this->op(['shift_id' => $shift['id'], 'lines' => [['product_id' => $this->product->id, 'unit' => 'portion', 'quantity' => $portions, 'allocations' => [['batch_id' => $this->batch->id, 'bucket' => 'ready', 'quantity' => $portions]]]]]);
        if ($serviceType) {
            $body['service_type'] = $serviceType;
        }
        $intent = $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $body)->assertCreated()->json('data');

        return $this->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', $this->op(['payment_method' => 'cash', 'cash_received' => 100000]))->assertOk()->json('data');
    }

    public function test_only_organization_managers_define_items_and_rules_and_tenants_are_isolated(): void
    {
        $item = $this->item('Kotak');
        foreach ([$this->manager, $this->cashier, $this->outsider] as $denied) {
            $this->act($denied)->getJson('/api/v1/organizations/'.$this->org->id.'/consumables')->assertForbidden();
            $this->postJson('/api/v1/organizations/'.$this->org->id.'/consumables/items', $this->op(['name' => 'X', 'unit' => 'pcs']))->assertForbidden();
            $this->postJson('/api/v1/organizations/'.$this->org->id.'/consumables/rules', $this->op(['service_type' => 'takeaway', 'consumable_item_id' => $item['id'], 'basis' => 'transaction', 'quantity' => 1]))->assertForbidden();
        }
        $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->org->id.'/consumables/items', $this->op(['name' => 'Kotak', 'unit' => 'pcs']))->assertStatus(409);
        // A manager of another organization cannot use this organization's item in its own rule.
        $this->act($this->outsider)->postJson('/api/v1/organizations/'.$this->otherOrg->id.'/consumables/rules', $this->op(['service_type' => 'takeaway', 'consumable_item_id' => $item['id'], 'basis' => 'transaction', 'quantity' => 1]))->assertStatus(422);
        $this->act($this->lead)->getJson('/api/v1/organizations/'.$this->org->id.'/consumables')->assertOk()->assertJsonPath('data.items.0.name', 'Kotak');
    }

    public function test_stock_is_visible_only_to_assigned_staff_and_purchases_only_to_managers(): void
    {
        $item = $this->item('Kotak');
        $this->act($this->unassigned)->getJson('/api/v1/stores/'.$this->store->id.'/consumables')->assertForbidden();
        $this->act($this->outsider)->getJson('/api/v1/stores/'.$this->store->id.'/consumables')->assertForbidden();
        $this->buy($item, 10, $this->cashier)->assertForbidden();
        $this->buy($item, 10, $this->outsider)->assertForbidden();
        $this->buy($item, 10, $this->manager)->assertCreated()->assertJsonPath('data.balance', 10);
        $this->assertSame(10, $this->balance($item));
    }

    public function test_purchase_adds_stock_and_records_the_cash_spent_once(): void
    {
        $item = $this->item('Kantong');
        $shift = $this->openShift();
        $body = $this->op(['consumable_item_id' => $item['id'], 'quantity' => 50, 'amount' => 15000, 'note' => 'Toko plastik', 'shift_id' => $shift['id'], 'shift_version' => $shift['version']]);
        $first = $this->act($this->manager)->postJson('/api/v1/stores/'.$this->store->id.'/consumables/purchases', $body)->assertCreated()->json('data');
        $this->assertSame(50, $first['balance']);
        $this->assertSame('belanja_operasional', $first['cash_movement']['category']);
        $this->assertDatabaseHas('cash_shifts', ['id' => $shift['id'], 'cash_out' => 15000, 'expected_cash' => 85000]);
        // Replaying the same operation never adds stock or cash out a second time.
        $this->postJson('/api/v1/stores/'.$this->store->id.'/consumables/purchases', $body)->assertCreated();
        $this->assertSame(50, $this->balance($item));
        $this->assertDatabaseHas('cash_shifts', ['id' => $shift['id'], 'cash_out' => 15000]);
        // The same id for different content is refused.
        $this->act($this->manager)->postJson('/api/v1/stores/'.$this->store->id.'/consumables/purchases', [...$body, 'quantity' => 51])->assertStatus(409);
        // Cash spent needs an amount.
        $this->buy($item, 5, null, ['shift_id' => $shift['id'], 'shift_version' => $shift['version'] + 1])->assertStatus(422);
    }

    public function test_takeaway_and_dine_in_use_their_own_rules_with_portion_and_transaction_basis(): void
    {
        $box = $this->item('Kotak');
        $spoon = $this->item('Sendok');
        $plate = $this->item('Piring');
        $this->rule($box, 'takeaway', 'portion', 1);
        $this->rule($spoon, 'takeaway', 'transaction', 1);
        $this->rule($plate, 'dine_in', 'portion', 1);
        foreach ([$box, $spoon, $plate] as $i) {
            $this->buy($i, 20)->assertCreated();
        }
        $shift = $this->openShift();
        $sale = $this->sell($shift, 3, 'takeaway');
        $this->assertSame('takeaway', $sale['service_type']);
        $this->assertEqualsCanonicalizing([['Kotak', 3, 0], ['Sendok', 1, 0]], array_map(fn ($c) => [$c['name'], $c['quantity'], $c['shortfall']], $sale['consumption']));
        $this->assertSame([17, 19, 20], [$this->balance($box), $this->balance($spoon), $this->balance($plate)]);
        $this->sell($shift, 2, 'dine_in');
        $this->assertSame([17, 19, 18], [$this->balance($box), $this->balance($spoon), $this->balance($plate)]);
        // A sale without a service type uses no supplies.
        $plain = $this->sell($shift, 1, null);
        $this->assertNull($plain['consumption']);
        $this->assertSame([17, 19, 18], [$this->balance($box), $this->balance($spoon), $this->balance($plate)]);
    }

    public function test_a_shortage_never_blocks_the_sale_and_is_reported(): void
    {
        $box = $this->item('Kotak');
        $this->rule($box, 'takeaway', 'portion', 1);
        $this->buy($box, 2)->assertCreated();
        $shift = $this->openShift();
        $sale = $this->sell($shift, 5, 'takeaway');
        $this->assertSame('completed', $sale['status']);
        $this->assertSame(5, $sale['consumption'][0]['quantity']);
        $this->assertSame(3, $sale['consumption'][0]['shortfall']);
        $this->assertSame(-3, $this->balance($box));
        $rows = $this->act($this->cashier)->getJson('/api/v1/stores/'.$this->store->id.'/consumables')->json('data.items');
        $this->assertTrue($rows[0]['short']);
        // Buying more repairs the balance.
        $this->buy($box, 10)->assertCreated();
        $this->assertSame(7, $this->balance($box));
    }

    public function test_paying_the_same_draft_again_never_deducts_supplies_twice(): void
    {
        $box = $this->item('Kotak');
        $this->rule($box, 'takeaway', 'portion', 1);
        $this->buy($box, 10)->assertCreated();
        $shift = $this->openShift();
        $body = $this->op(['shift_id' => $shift['id'], 'service_type' => 'takeaway', 'lines' => [['product_id' => $this->product->id, 'unit' => 'portion', 'quantity' => 2, 'allocations' => [['batch_id' => $this->batch->id, 'bucket' => 'ready', 'quantity' => 2]]]]]);
        $intent = $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $body)->assertCreated()->json('data');
        $pay = $this->op(['payment_method' => 'cash', 'cash_received' => 100000]);
        $this->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', $pay)->assertOk();
        $this->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', $pay)->assertOk();
        $this->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', [...$pay, 'operation_id' => (string) Str::uuid()])->assertOk();
        $this->assertSame(8, $this->balance($box));
    }

    public function test_inactive_items_are_skipped_and_an_unknown_service_type_is_rejected(): void
    {
        $box = $this->item('Kotak');
        $this->rule($box, 'takeaway', 'portion', 1);
        $this->buy($box, 10)->assertCreated();
        $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->org->id.'/consumables/items/'.$box['id'].'/status', $this->op(['version' => 1, 'active' => false, 'reason' => 'Tidak dipakai']))->assertOk();
        $shift = $this->openShift();
        $sale = $this->sell($shift, 2, 'takeaway');
        $this->assertNull($sale['consumption']);
        $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $this->op(['shift_id' => $shift['id'], 'service_type' => 'delivery', 'lines' => [['product_id' => $this->product->id, 'unit' => 'portion', 'quantity' => 1, 'allocations' => [['batch_id' => $this->batch->id, 'bucket' => 'ready', 'quantity' => 1]]]]]))->assertStatus(422);
    }

    public function test_a_physical_count_sets_the_balance_and_needs_a_reason(): void
    {
        $box = $this->item('Kotak');
        $this->buy($box, 10)->assertCreated();
        $body = $this->op(['consumable_item_id' => $box['id'], 'counted' => 7, 'reason' => 'Hitung fisik malam']);
        $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/consumables/counts', $body)->assertForbidden();
        $this->act($this->manager)->postJson('/api/v1/stores/'.$this->store->id.'/consumables/counts', $body)->assertCreated()->assertJsonPath('data.balance_before', 10)->assertJsonPath('data.entry.delta', -3);
        $this->assertSame(7, $this->balance($box));
        $this->postJson('/api/v1/stores/'.$this->store->id.'/consumables/counts', $this->op(['consumable_item_id' => $box['id'], 'counted' => 7]))->assertStatus(422);
    }

    public function test_a_refund_does_not_return_used_supplies(): void
    {
        $box = $this->item('Kotak');
        $this->rule($box, 'takeaway', 'portion', 1);
        $this->buy($box, 10)->assertCreated();
        $sale = $this->sell($this->openShift(), 2, 'takeaway');
        $this->assertSame(8, $this->balance($box));
        $this->assertDatabaseMissing('consumable_stock_entries', ['source_type' => 'refund']);
        $this->assertSame(2, DB::table('consumable_stock_entries')->where('source_id', $sale['id'])->sum(DB::raw('-delta')));
    }
}
