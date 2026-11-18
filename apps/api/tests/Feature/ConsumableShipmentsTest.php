<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Center-to-booth shipments of packaging and cutlery: stock out at the center, confirmed actual receipt at the booth. */
class ConsumableShipmentsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $center;

    private Organization $partner;

    private Organization $rival;

    private Store $warehouse;

    private Store $booth;

    private Store $rivalBooth;

    private User $owner;

    private User $keeper;

    private User $lead;

    private User $manager;

    private User $cashier;

    private User $rivalLead;

    private array $centerBox;

    private array $boothBox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->center = Organization::create(['name' => 'Pusat', 'type' => 'center']);
        $this->partner = Organization::create(['name' => 'Mitra A', 'type' => 'partner']);
        $this->rival = Organization::create(['name' => 'Mitra B', 'type' => 'partner']);
        $this->warehouse = Store::create(['organization_id' => $this->center->id, 'name' => 'Gudang Pusat', 'address' => 'Pacitan']);
        $this->booth = Store::create(['organization_id' => $this->partner->id, 'name' => 'Booth A1', 'address' => 'Pacitan']);
        $this->rivalBooth = Store::create(['organization_id' => $this->rival->id, 'name' => 'Booth B1', 'address' => 'Pacitan']);
        $this->owner = $this->user($this->center, 'owner');
        $this->keeper = $this->user($this->center, 'warehouse', [$this->warehouse]);
        $this->lead = $this->user($this->partner, 'partner_lead');
        $this->manager = $this->user($this->partner, 'store_manager', [$this->booth]);
        $this->cashier = $this->user($this->partner, 'cashier', [$this->booth]);
        $this->rivalLead = $this->user($this->rival, 'partner_lead');
        $this->centerBox = $this->item($this->center, $this->owner, 'Kotak pusat');
        $this->boothBox = $this->item($this->partner, $this->lead, 'Kotak booth');
        $this->stockCenter(100);
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

    private function item(Organization $org, User $by, string $name): array
    {
        return $this->act($by)->postJson('/api/v1/organizations/'.$org->id.'/consumables/items', $this->op(['name' => $name, 'unit' => 'pcs']))->assertCreated()->json('data');
    }

    private function stockCenter(int $quantity): void
    {
        $this->act($this->owner)->postJson('/api/v1/stores/'.$this->warehouse->id.'/consumables/purchases', $this->op(['consumable_item_id' => $this->centerBox['id'], 'quantity' => $quantity]))->assertCreated();
    }

    private function balance(User $u, Store $store, array $item): int
    {
        foreach ($this->act($u)->getJson('/api/v1/stores/'.$store->id.'/consumables')->assertOk()->json('data.items') as $row) {
            if ($row['id'] === $item['id']) {
                return $row['balance'];
            }
        }
        $this->fail('Item not listed');
    }

    private function send(int $quantity = 40, ?User $by = null, ?Store $to = null, ?string $note = 'Kirim mingguan'): TestResponse
    {
        return $this->act($by ?? $this->keeper)->postJson('/api/v1/stores/'.$this->warehouse->id.'/consumable-shipments', $this->op(['destination_store_id' => ($to ?? $this->booth)->id, 'note' => $note, 'lines' => [['consumable_item_id' => $this->centerBox['id'], 'quantity' => $quantity]]]));
    }

    private function receiveBody(array $shipment, int $received, ?string $note = null): array
    {
        return $this->op(['version' => $shipment['version'], 'note' => $note, 'lines' => [['line_id' => $shipment['lines'][0]['id'], 'destination_item_id' => $this->boothBox['id'], 'quantity_received' => $received]]]);
    }

    public function test_only_center_warehouse_staff_can_send_and_stock_leaves_the_warehouse_once(): void
    {
        $this->send(40, $this->cashier)->assertForbidden();
        $this->send(40, $this->lead)->assertForbidden();
        $this->send(40, $this->rivalLead)->assertForbidden();
        $body = $this->op(['destination_store_id' => $this->booth->id, 'lines' => [['consumable_item_id' => $this->centerBox['id'], 'quantity' => 40]]]);
        $first = $this->act($this->keeper)->postJson('/api/v1/stores/'.$this->warehouse->id.'/consumable-shipments', $body)->assertCreated()->json('data');
        $this->assertSame('dispatched', $first['status']);
        $this->assertSame(60, $this->balance($this->owner, $this->warehouse, $this->centerBox));
        // Replay never ships twice; the same id for different content is refused.
        $this->act($this->keeper)->postJson('/api/v1/stores/'.$this->warehouse->id.'/consumable-shipments', $body)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->assertSame(60, $this->balance($this->owner, $this->warehouse, $this->centerBox));
        $other = $body;
        $other['lines'][0]['quantity'] = 41;
        $this->act($this->keeper)->postJson('/api/v1/stores/'.$this->warehouse->id.'/consumable-shipments', $other)->assertStatus(409);
        // The owner of the center may send as well.
        $this->send(10, $this->owner)->assertCreated();
        $this->assertSame(50, $this->balance($this->owner, $this->warehouse, $this->centerBox));
    }

    public function test_the_center_cannot_ship_more_than_it_holds_or_to_invalid_targets(): void
    {
        $this->send(101)->assertStatus(409);
        $this->assertSame(100, $this->balance($this->owner, $this->warehouse, $this->centerBox));
        $this->send(5, null, $this->warehouse)->assertStatus(422);
        $this->act($this->keeper)->postJson('/api/v1/stores/'.$this->warehouse->id.'/consumable-shipments', $this->op(['destination_store_id' => $this->booth->id, 'lines' => [['consumable_item_id' => $this->centerBox['id'], 'quantity' => 1], ['consumable_item_id' => $this->centerBox['id'], 'quantity' => 1]]]))->assertStatus(422);
        // A partner's own item cannot be shipped by the center.
        $this->act($this->keeper)->postJson('/api/v1/stores/'.$this->warehouse->id.'/consumable-shipments', $this->op(['destination_store_id' => $this->booth->id, 'lines' => [['consumable_item_id' => $this->boothBox['id'], 'quantity' => 1]]]))->assertStatus(422);
        $this->booth->update(['active' => false]);
        $this->send(5)->assertStatus(422);
    }

    public function test_each_side_sees_only_its_own_shipments_and_its_own_item_ids(): void
    {
        $shipment = $this->send(40)->json('data');
        $this->act($this->keeper)->getJson('/api/v1/stores/'.$this->warehouse->id.'/consumable-shipments')->assertOk()->assertJsonPath('can_send', true)->assertJsonPath('data.0.direction', 'outgoing')->assertJsonPath('data.0.lines.0.source_item_id', $this->centerBox['id'])->assertJsonMissingPath('data.0.lines.0.destination_item_id');
        $this->assertContains($this->booth->id, array_column($this->getJson('/api/v1/stores/'.$this->warehouse->id.'/consumable-shipments')->json('destinations'), 'id'));
        $incoming = $this->act($this->cashier)->getJson('/api/v1/stores/'.$this->booth->id.'/consumable-shipments')->assertOk()->assertJsonPath('data.0.id', $shipment['id'])->assertJsonPath('data.0.direction', 'incoming')->assertJsonPath('can_receive', false)->assertJsonMissingPath('data.0.lines.0.source_item_id');
        $this->assertSame([], $incoming->json('destinations'));
        $this->act($this->manager)->getJson('/api/v1/stores/'.$this->booth->id.'/consumable-shipments')->assertJsonPath('can_receive', true);
        // Another organization sees nothing and cannot open this store's list.
        $this->act($this->rivalLead)->getJson('/api/v1/stores/'.$this->booth->id.'/consumable-shipments')->assertForbidden();
        $this->getJson('/api/v1/stores/'.$this->rivalBooth->id.'/consumable-shipments')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_the_booth_confirms_actual_quantities_into_its_own_item(): void
    {
        $shipment = $this->send(40)->json('data');
        $incoming = $this->act($this->manager)->getJson('/api/v1/stores/'.$this->booth->id.'/consumable-shipments')->json('data.0');
        $body = $this->receiveBody($incoming, 40);
        $this->act($this->cashier)->postJson('/api/v1/consumable-shipments/'.$shipment['id'].'/receive', $body)->assertForbidden();
        $this->act($this->rivalLead)->postJson('/api/v1/consumable-shipments/'.$shipment['id'].'/receive', $body)->assertForbidden();
        $this->assertSame(0, $this->balance($this->manager, $this->booth, $this->boothBox));
        $done = $this->act($this->manager)->postJson('/api/v1/consumable-shipments/'.$shipment['id'].'/receive', $body)->assertOk()->json('data');
        $this->assertSame('received', $done['status']);
        $this->assertSame(40, $this->balance($this->manager, $this->booth, $this->boothBox));
        // Replaying the same operation never credits twice; a new operation on a received shipment is refused.
        $this->postJson('/api/v1/consumable-shipments/'.$shipment['id'].'/receive', $body)->assertOk();
        $this->assertSame(40, $this->balance($this->manager, $this->booth, $this->boothBox));
        $this->postJson('/api/v1/consumable-shipments/'.$shipment['id'].'/receive', [...$body, 'operation_id' => (string) Str::uuid()])->assertStatus(409);
        $this->assertSame(40, $this->balance($this->manager, $this->booth, $this->boothBox));
    }

    public function test_a_short_receipt_needs_a_reason_and_credits_only_what_arrived(): void
    {
        $shipment = $this->send(40)->json('data');
        $this->act($this->manager);
        $this->postJson('/api/v1/consumable-shipments/'.$shipment['id'].'/receive', $this->receiveBody($shipment, 35))->assertStatus(422);
        $this->postJson('/api/v1/consumable-shipments/'.$shipment['id'].'/receive', $this->receiveBody($shipment, 41, 'Lebih'))->assertStatus(422);
        $this->postJson('/api/v1/consumable-shipments/'.$shipment['id'].'/receive', [...$this->receiveBody($shipment, 40), 'version' => 9])->assertStatus(409);
        $this->assertSame(0, $this->balance($this->manager, $this->booth, $this->boothBox));
        $this->act($this->manager)->postJson('/api/v1/consumable-shipments/'.$shipment['id'].'/receive', $this->receiveBody($shipment, 35, '5 kotak penyok di jalan'))->assertOk()->assertJsonPath('data.lines.0.quantity_received', 35)->assertJsonPath('data.receive_note', '5 kotak penyok di jalan');
        $this->assertSame(35, $this->balance($this->manager, $this->booth, $this->boothBox));
        // The center's balance already dropped by what it sent; the difference stays visible on the shipment.
        $this->assertSame(60, $this->balance($this->owner, $this->warehouse, $this->centerBox));
    }

    public function test_a_booth_cannot_map_to_another_organizations_item_or_skip_lines(): void
    {
        $shipment = $this->send(40)->json('data');
        $foreign = $this->item($this->rival, $this->rivalLead, 'Kotak lain');
        $this->act($this->manager);
        $bad = $this->receiveBody($shipment, 40);
        $bad['lines'][0]['destination_item_id'] = $foreign['id'];
        $this->postJson('/api/v1/consumable-shipments/'.$shipment['id'].'/receive', $bad)->assertStatus(422);
        $this->postJson('/api/v1/consumable-shipments/'.$shipment['id'].'/receive', $this->op(['version' => 1, 'lines' => [['line_id' => (string) Str::ulid(), 'destination_item_id' => $this->boothBox['id'], 'quantity_received' => 1]]]))->assertStatus(422);
        $this->assertSame(0, $this->balance($this->manager, $this->booth, $this->boothBox));
    }

    public function test_received_stock_is_used_by_sales_rules_like_any_other_stock(): void
    {
        $shipment = $this->send(10)->json('data');
        $this->act($this->manager)->postJson('/api/v1/consumable-shipments/'.$shipment['id'].'/receive', $this->receiveBody($shipment, 10))->assertOk();
        $this->act($this->lead)->postJson('/api/v1/organizations/'.$this->partner->id.'/consumables/rules', $this->op(['service_type' => 'takeaway', 'consumable_item_id' => $this->boothBox['id'], 'basis' => 'transaction', 'quantity' => 2]))->assertOk();
        $this->assertSame(10, $this->balance($this->manager, $this->booth, $this->boothBox));
    }
}
