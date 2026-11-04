<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Inventory\Application\FoodSafety;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Reliability\Application\Inbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FoodReliabilityTest extends TestCase
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

    private function policy(string $fefo = 'enforce', ?int $life = 60): array
    {
        return ['operation_id' => (string) Str::uuid(), 'version' => 0, 'enabled' => true, 'block_expired' => true, 'fefo' => $fefo, 'ready_life_minutes' => $life, 'reference' => 'Approved SOP by organization lead'];
    }

    public function test_sop_manager_only_and_existing_ready_requires_actual_timestamp_without_extension(): void
    {
        $url = '/api/v1/organizations/'.$this->org->id.'/food-safety';
        $body = $this->policy();
        $i = $this->intent($this->open());
        foreach ([$this->cashier, $this->warehouse, $this->outside] as $u) {
            $this->act($u)->postJson($url, $body)->assertForbidden();
        }$this->act($this->manager)->postJson($url, $body)->assertOk()->assertJsonPath('data.version', 1);
        $this->postJson($url, $body)->assertOk();
        $this->act($this->cashier)->postJson('/api/v1/sales/intents/'.$i['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 20000])->assertConflict();
        $b = $this->batch->fresh();
        $review = ['operation_id' => (string) Str::uuid(), 'batch_id' => $b->id, 'expected_revision' => $b->stock_revision, 'prepared_at' => now()->subMinutes(20)->toIso8601String(), 'reference' => 'Actual preparation record'];
        $this->act($this->manager)->postJson($url.'/ready', $review)->assertOk();
        $this->postJson($url.'/ready', [...$review, 'operation_id' => (string) Str::uuid(), 'expected_revision' => $b->stock_revision + 1, 'prepared_at' => now()->toIso8601String()])->assertConflict();
        $this->pay($i);
        $this->travel(61)->minutes();
        $this->act($this->cashier)->postJson('/api/v1/stores/'.$this->store->id.'/sales/intents', $this->cart(['id' => $i['cash_shift_id']]))->assertConflict();
    }

    public function test_recall_blocks_checkout_and_replay_is_one_event_without_stock_or_finance_changes(): void
    {
        $shift = $this->open();
        $i = $this->intent($shift);
        $url = '/api/v1/organizations/'.$this->org->id.'/food-safety/recalls';
        $v = ['operation_id' => (string) Str::uuid(), 'product_id' => $this->product->id, 'batch_code' => $this->batch->batch_code, 'reason' => 'Confirmed inspection issue', 'reference' => 'Recall notice'];
        $r = $this->act($this->manager)->postJson($url, $v)->assertCreated()->json('data');
        $this->postJson($url, $v)->assertCreated();
        $this->assertDatabaseCount('batch_recalls', 1);
        $this->act($this->cashier)->postJson('/api/v1/sales/intents/'.$i['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 20000])->assertConflict();
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(30, $this->batch->fresh()->ready_pieces);
        $this->act($this->manager)->postJson($url.'/'.$r['id'].'/withdraw', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Inspection disposition approved'])->assertOk();
        $this->pay($i);
    }

    public function test_inbox_scope_assignment_dedup_preferences_and_low_stock_episode(): void
    {
        $inbox = app(Inbox::class);
        DB::transaction(function () use ($inbox) {
            $inbox->emit('EVENT1', $this->org->id, null, 'order.created', 'Needs review', '/app', ['partner_lead']);
            $inbox->emit('EVENT1', $this->org->id, null, 'order.created', 'Needs review', '/app', ['partner_lead']);
        });
        $this->assertDatabaseCount('inbox_entries', 1);
        $id = DB::table('inbox_entries')->value('id');
        $this->act($this->outside)->getJson('/api/v1/inbox')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/v1/inbox/'.$id.'/read')->assertNotFound();
        $this->act($this->manager)->getJson('/api/v1/inbox')->assertOk()->assertJsonPath('unread_count', 1);
        $this->postJson('/api/v1/inbox/'.$id.'/read')->assertOk();
        $this->postJson('/api/v1/inbox/'.$id.'/read')->assertOk();
        $this->getJson('/api/v1/inbox')->assertJsonPath('unread_count', 0);
        $this->postJson('/api/v1/inbox/preferences', ['version' => 0, 'email_enabled' => true])->assertOk();
        $this->postJson('/api/v1/inbox/preferences', ['version' => 0, 'email_enabled' => false])->assertConflict();
        DB::transaction(fn () => $inbox->emit('EVENT2', $this->org->id, null, 'order.created', 'Needs review', '/app', ['partner_lead']));
        $this->assertDatabaseCount('notification_deliveries', 1);
        DB::table('inventory_minimums')->insert(['id' => (string) Str::ulid(), 'store_id' => $this->store->id, 'product_id' => $this->product->id, 'minimum_pieces' => 200, 'version' => 1, 'updated_by' => $this->manager->id, 'reason' => 'Actual threshold', 'created_at' => now(), 'updated_at' => now()]);
        DB::transaction(function () use ($inbox) {
            $inbox->lowStock($this->store->id, $this->product->id);
            $inbox->lowStock($this->store->id, $this->product->id);
        });
        $this->assertSame(1, DB::table('stock_warning_states')->value('episode'));
        $this->assertSame(1, DB::table('inbox_entries')->where('event_type', 'stock.low')->where('user_id', $this->manager->id)->count());
    }

    public function test_fefo_suggestions_are_scoped_ordered_and_enforcement_is_transactional(): void
    {
        $this->batch->forceFill(['expires_on' => '2030-02-01'])->save();
        $old = DB::transaction(fn () => app(StockLedger::class)->credit($this->org->id, $this->store->id, $this->product->only(['id', 'sku', 'name', 'condition', 'pack_size']), 'OLDER', '2030-01-01', 2, $this->manager->id, 'shipment_receipt', (string) Str::ulid(), 'usable', 'Older fixture'));
        $url = '/api/v1/organizations/'.$this->org->id.'/food-safety';
        $this->act($this->manager)->postJson($url, $this->policy('suggest', null))->assertOk();
        $query = '/api/v1/stores/'.$this->store->id.'/food-safety/fefo?'.http_build_query(['product_id' => $this->product->id, 'bucket' => 'usable', 'condition' => 'frozen', 'pack_size' => 10]);
        $this->act($this->outside)->getJson($query)->assertForbidden();
        $this->act($this->cashier)->getJson($query)->assertOk()->assertJsonPath('mode', 'suggest')->assertJsonPath('data.0.id', $old['id']);
        $shift = $this->open();
        $intent = $this->intent($shift, 1, 'pack', 'usable');
        $this->act($this->manager)->postJson($url, [...$this->policy('enforce', null), 'version' => 1])->assertOk();
        $this->act($this->cashier)->postJson('/api/v1/sales/intents/'.$intent['id'].'/pay', ['operation_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => 50000])->assertConflict();
        $this->assertDatabaseCount('sales', 0);
        $b = $this->batch->fresh();
        $this->act($this->warehouse)->postJson('/api/v1/stores/'.$this->store->id.'/inventory/processes', ['operation_id' => (string) Str::uuid(), 'batch_id' => $b->id, 'expected_revision' => $b->stock_revision, 'kind' => 'open', 'quantity_packs' => 1, 'reason' => 'Must use older first'])->assertConflict();
        $this->assertSame(60, $this->batch->fresh()->usable_pieces);
    }

    public function test_smtp_delivery_retries_without_secret_errors_and_rechecks_opt_in(): void
    {
        config(['reliability.smtp_enabled' => true, 'mail.default' => 'smtp']);
        $inbox = app(Inbox::class);
        $inbox->preferences($this->manager, ['version' => 0, 'email_enabled' => true]);
        DB::transaction(fn () => $inbox->emit('EMAIL1', $this->org->id, null, 'food.recalled', 'Review', '/app/organizations/'.$this->org->id));
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('SECRET-SMTP-ERROR'));
        $this->assertSame(['sent' => 0, 'failed' => 1], $inbox->deliver(1));
        $d = DB::table('notification_deliveries')->first();
        $this->assertSame('retry', $d->status);
        $this->assertSame('transport_failed', $d->error_code);
        $this->assertSame(1, $d->attempts);
        $this->assertNull($d->lease_token);
        $this->travel(3)->minutes();
        $inbox->preferences($this->manager, ['version' => 1, 'email_enabled' => false]);
        $this->assertSame(['sent' => 0, 'failed' => 0], $inbox->deliver(1));
        $this->assertSame('cancelled', DB::table('notification_deliveries')->value('status'));
    }

    public function test_cooked_deadline_uses_explicit_minutes_and_never_extends_mixed_old_stock(): void
    {
        $this->travelTo(now()->startOfMinute());
        $url = '/api/v1/organizations/'.$this->org->id.'/food-safety';
        $this->act($this->manager)->postJson($url, $this->policy('off', 60))->assertOk();
        $this->batch->forceFill(['ready_expires_at' => now()->addMinutes(20)])->save();
        $b = $this->batch->fresh();
        $this->act($this->warehouse)->postJson('/api/v1/stores/'.$this->store->id.'/inventory/processes', ['operation_id' => (string) Str::uuid(), 'batch_id' => $b->id, 'expected_revision' => $b->stock_revision, 'kind' => 'cook', 'input_pieces' => 4, 'output_pieces' => 3, 'reason' => 'Actual preparation'])->assertCreated();
        $this->assertSame(33, $b->fresh()->ready_pieces);
        $this->assertSame(now()->addMinutes(20)->timestamp, $b->fresh()->ready_expires_at->timestamp);
        $this->travelTo(CarbonImmutable::parse('2026-11-04T23:40:00+07:00'));
        $this->batch->forceFill(['expires_on' => '2026-11-04', 'ready_expires_at' => now()->addMinutes(60)])->save();
        $deadline = app(FoodSafety::class)->suggestions($this->store->id, $this->product->id, 'ready', 'frozen', null);
        $this->assertSame('off', $deadline['mode']);
        $this->act($this->manager)->postJson($url, [...$this->policy('suggest', 60), 'version' => 1])->assertOk();
        $deadline = app(FoodSafety::class)->suggestions($this->store->id, $this->product->id, 'ready', 'frozen', null);
        $this->assertSame(CarbonImmutable::parse('2026-11-04T23:59:59+07:00')->timestamp, CarbonImmutable::parse($deadline['data'][0]['deadline'])->timestamp);

    }
}
