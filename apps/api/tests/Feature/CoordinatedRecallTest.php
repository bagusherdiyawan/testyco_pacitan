<?php

namespace Tests\Feature;

use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Inventory\Application\FoodSafety;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\WholesaleScenario;

class CoordinatedRecallTest extends WholesaleScenario
{
    private function campaign(array $d): array
    {
        $r = $this->act($this->owner)->postJson('/api/v1/organizations/'.$this->center->id.'/food-safety/recalls', ['operation_id' => (string) Str::uuid(), 'product_id' => $this->supplierProduct->id, 'batch_code' => 'B-001', 'reference' => 'Inspection recall', 'reason' => 'Confirmed supplier issue'])->assertCreated()->json('data');
        $body = ['operation_id' => (string) Str::uuid(), 'recall_id' => $r['id'], 'instructions' => 'Inspect traced goods and record actual disposition'];
        $url = '/api/v1/organizations/'.$this->center->id.'/recalls';
        $c = $this->postJson($url, $body)->assertCreated()->json('data');
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('data.id', $c['id']);

        return $c;
    }

    public function test_real_ledger_trace_scopes_notice_and_recipient_hold_release_without_stock_writes(): void
    {
        $d = $this->received();
        $before = DB::table('stock_entries')->count();
        $c = $this->campaign($d);
        $p = '/api/v1/organizations/'.$this->partner->id.'/recalls/'.$c['id'];
        $view = $this->act($this->lead)->getJson($p)->assertOk()->json();
        $target = $view['targets']['data'][0];
        $this->assertSame('notified', $target['status']);
        $b = InventoryBatch::findOrFail($target['inventory_batch_id']);
        $safety = app(FoodSafety::class);
        $this->assertTrue($safety->eligible($b, 'usable'));
        $this->act($this->owner)->getJson($p)->assertForbidden();
        $this->act($this->outsider)->getJson($p)->assertForbidden();
        $v = ['operation_id' => (string) Str::uuid(), 'version' => 1, 'expected_revision' => $b->stock_revision, 'action' => 'hold', 'observed_pieces' => 136, 'evidence_reference' => 'Physical check', 'reason' => 'Actual goods isolated'];
        $this->act($this->cashier)->postJson($p.'/targets/'.$target['id'], $v)->assertForbidden();
        $this->act($this->lead)->postJson($p.'/targets/'.$target['id'], $v)->assertOk()->assertJsonPath('data.status', 'held');
        $this->postJson($p.'/targets/'.$target['id'], $v)->assertOk();
        $this->assertFalse($safety->eligible($b, 'usable'));
        $this->act($this->owner)->postJson('/api/v1/organizations/'.$this->center->id.'/recalls/'.$c['id'].'/close', ['operation_id' => (string) Str::uuid(), 'version' => 1, 'reason' => 'Source coordination closed'])->assertOk();
        $this->assertFalse($safety->eligible($b, 'usable'));
        $this->act($this->lead)->postJson($p.'/targets/'.$target['id'], [...$v, 'operation_id' => (string) Str::uuid(), 'version' => 2, 'action' => 'release', 'reason' => 'Disposition evidence verified'])->assertOk();
        $this->assertTrue($safety->eligible($b, 'usable'));
        $this->assertSame($before, DB::table('stock_entries')->count());
        $this->assertDatabaseCount('recall_campaigns', 1);
    }

    public function test_in_transit_receipt_is_linked_after_campaign_without_lot_code_guessing(): void
    {
        [$o,$b] = $this->ready();
        $d = $this->dispatch($o, $b, 3);
        $c = $this->campaign($d);
        $this->assertSame(1, DB::table('recall_targets')->count());
        $this->act($this->lead)->postJson('/api/v1/shipments/'.$d['shipment']['id'].'/receive', ['operation_id' => (string) Str::uuid(), 'version' => $d['order']['version'], 'lines' => [['shipment_line_id' => $d['shipment']['lines'][0]['id'], 'good_packs' => 3, 'damaged_packs' => 0]]])->assertOk();
        $this->assertSame(2, DB::table('recall_targets')->count());
        $this->assertSame(1, DB::table('recall_targets')->where('organization_id', $this->partner->id)->count());
        $foreign = Organization::create(['name' => 'Unrelated', 'type' => 'partner']);
        $this->assertSame(0, DB::table('recall_targets')->where('organization_id', $foreign->id)->count());
    }
}
