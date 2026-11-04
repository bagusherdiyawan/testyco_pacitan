<?php

namespace App\Modules\Reliability\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Inventory\Application\FoodSafety;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecallCoordinator
{
    public function __construct(private Access $access, private Operations $ops, private Inbox $inbox) {}

    private function manager(User $u, string $org): void
    {
        abort_unless(in_array($this->access->role($u, $org)?->value, ['owner', 'admin', 'partner_lead'], true), 403);
    }

    public function index(User $u, string $org): array
    {
        $this->manager($u, $org);
        $ids = DB::table('recall_targets')->where('organization_id', $org)->select('recall_campaign_id');

        return ['organization_id' => $org, ...DB::table('recall_campaigns')->where(fn ($q) => $q->where('organization_id', $org)->orWhereIn('id', $ids))->orderByDesc('id')->paginate(50)->toArray()];
    }

    public function show(User $u, string $org, string $id): array
    {
        $this->manager($u, $org);
        $c = DB::table('recall_campaigns')->find($id);
        abort_unless($c, 404);
        $own = $c->organization_id === $org;
        $q = DB::table('recall_targets as t')->join('inventory_batches as b', 'b.id', '=', 't.inventory_batch_id')->join('stores as s', 's.id', '=', 'b.store_id')->join('organizations as o', 'o.id', '=', 't.organization_id')->where('t.recall_campaign_id', $id);
        if (! $own) {
            $q->where('t.organization_id', $org);
        }
        abort_unless($own || (clone $q)->exists(), 403);
        $cols = ['t.id', 't.organization_id', 't.status', 't.version', 't.evidence_reference', 't.reason', 't.observed_pieces', 't.reviewed_at', 'b.batch_code', 's.name as store_name', 'o.name as organization_name'];
        $cols = [...$cols, 'b.id as inventory_batch_id', 'b.product_id', 'b.stock_revision', 'b.usable_pieces', 'b.opened_pieces', 'b.ready_pieces', 'b.quarantined_pieces'];
        $rows = $q->select($cols)->orderBy('t.id')->paginate(50)->through(function ($r) use ($org) {
            $r = (array) $r;
            if ($r['organization_id'] !== $org) {
                foreach (['inventory_batch_id', 'product_id', 'stock_revision', 'usable_pieces', 'opened_pieces', 'ready_pieces', 'quarantined_pieces'] as $k) {
                    unset($r[$k]);
                }
            }

            return $r;
        })->toArray();

        return ['organization_id' => $org, 'campaign' => (array) $c, 'is_source' => $own, 'targets' => $rows];
    }

    public function start(User $u, string $org, array $v): array
    {
        $this->manager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'recall.coordinate:'.$v['recall_id'], $v, function () use ($u, $org, $v) {
            $r = DB::table('batch_recalls')->where('organization_id', $org)->lockForUpdate()->find($v['recall_id']);
            abort_unless($r && $r->status === 'active', 409);
            abort_if(DB::table('recall_campaigns')->where('batch_recall_id', $r->id)->exists(), 409, 'Koordinasi recall ini sudah ada.');
            $id = (string) Str::ulid();
            $c = ['id' => $id, 'batch_recall_id' => $r->id, 'organization_id' => $org, 'status' => 'active', 'version' => 1, 'instructions' => $v['instructions'], 'created_by' => $u->id, 'created_at' => now()->toIso8601String()];
            DB::table('recall_campaigns')->insert($c);
            $roots = InventoryBatch::where('organization_id', $org)->where('product_id', $r->product_id)->where('batch_code', $r->batch_code)->orderBy('id')->get();
            foreach ($roots as $b) {
                $this->target($id, $b, null, 'root', null, true);
            }
            $this->expand($id, $roots->pluck('id')->all());
            Audit::record($u->id, $org, 'food.recall_coordinated', $id, null, ['campaign_id' => $id, 'recall_id' => $r->id]);

            return $c;
        });
    }

    private function edges(array $parents): array
    {
        $rows = [];
        foreach (['shipment_receipt' => 'shipment_lines', 'replacement_receipt' => 'replacement_lines', 'return_receipt' => 'return_lines'] as $type => $table) {
            $items = DB::table('stock_entries as e')->join($table.' as l', 'l.id', '=', 'e.source_id')->where('e.source_type', $type)->where('e.quantity_pieces', '>', 0)->whereIn('l.inventory_batch_id', $parents)->whereIn('e.bucket', ['usable', 'quarantined'])->select(['l.inventory_batch_id as parent', 'e.inventory_batch_id as child', 'e.source_id'])->distinct()->limit(10001)->get();
            abort_if($items->count() > 10000, 422, 'Jejak recall terlalu besar; butuh pemeriksaan operasional.');
            foreach ($items as $r) {
                $rows[] = ['parent' => $r->parent, 'child' => $r->child, 'source_id' => $r->source_id, 'source_type' => $type];
            }
        }

        return $rows;
    }

    private function target(string $campaign, InventoryBatch $b, ?string $parent, string $type, ?string $source, bool $root = false): void
    {
        $id = (string) Str::ulid();
        $inserted = DB::table('recall_targets')->insertOrIgnore(['id' => $id, 'recall_campaign_id' => $campaign, 'organization_id' => $b->organization_id, 'inventory_batch_id' => $b->id, 'parent_batch_id' => $parent, 'source_type' => $type, 'source_id' => $source, 'status' => $root ? 'held' : 'notified', 'version' => 1, 'created_at' => now()]);
        if ($inserted) {
            $this->inbox->emit('recall-target:'.$id, $b->organization_id, $b->store_id, 'food.coordinated_recall', 'Pemberitahuan recall kiriman: verifikasi stok dan tindakan fisik', '/app/organizations/'.$b->organization_id.'/recalls/'.$campaign);
            app(FoodSafety::class)->forget($b->organization_id);
        }
    }

    private function expand(string $campaign, array $front): void
    {
        $seen = array_fill_keys($front, true);
        for ($depth = 0; $front; $depth++) {
            abort_if($depth >= 100 || count($seen) > 10000, 422, 'Kedalaman/ukuran jejak recall memerlukan penanganan khusus.');
            $next = [];
            foreach ($this->edges($front) as $e) {
                if (isset($seen[$e['child']])) {
                    continue;
                }$seen[$e['child']] = true;
                $b = InventoryBatch::findOrFail($e['child']);
                $this->target($campaign, $b, $e['parent'], $e['source_type'], $e['source_id']);
                $next[] = $b->id;
            }$front = $next;
        }
    }

    /** Receipt hook: only verified ledger provenance, including late receipts, never lot-code-only matching. */
    public function received(InventoryBatch $b, string $type, string $source): void
    {
        $table = match ($type) {
            'shipment_receipt' => 'shipment_lines','replacement_receipt' => 'replacement_lines','return_receipt' => 'return_lines',default => null
        };
        if (! $table) {
            return;
        }
        $parent = DB::table($table)->where('id', $source)->value('inventory_batch_id');
        if (! $parent) {
            return;
        }
        $ids = DB::table('recall_targets as t')->join('recall_campaigns as c', 'c.id', '=', 't.recall_campaign_id')->where('t.inventory_batch_id', $parent)->where('c.status', 'active')->pluck('c.id');
        foreach ($ids as $id) {
            $this->target($id, $b, $parent, $type, $source);
        }
    }

    public function refresh(User $u, string $org, string $id, array $v): array
    {
        $this->manager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'recall.refresh:'.$id, $v, function () use ($org, $id) {
            $c = DB::table('recall_campaigns')->where('organization_id', $org)->find($id);
            abort_unless($c && $c->status === 'active', 403);
            $roots = DB::table('recall_targets')->where('recall_campaign_id', $id)->where('source_type', 'root')->pluck('inventory_batch_id')->all();
            $this->expand($id, $roots);

            return ['id' => $id, 'organization_id' => $org, 'status' => 'active'];
        });
    }

    public function decide(User $u, string $org, string $id, string $target, array $v): array
    {
        $this->manager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'recall.target:'.$target, $v, function () use ($u, $org, $id, $target, $v) {
            $hint = DB::table('recall_targets')->where('recall_campaign_id', $id)->where('organization_id', $org)->find($target);
            abort_unless($hint, 404);
            $b = InventoryBatch::where('organization_id', $org)->lockForUpdate()->findOrFail($hint->inventory_batch_id);
            $t = DB::table('recall_targets')->lockForUpdate()->find($target);
            abort_unless((int) $t->version === (int) $v['version'] && $b->stock_revision === (int) $v['expected_revision'], 409);
            abort_if($v['action'] === 'hold' && $t->status === 'held' || $v['action'] === 'release' && $t->status === 'released', 409);
            $d = ['status' => $v['action'] === 'hold' ? 'held' : 'released', 'version' => (int) $t->version + 1, 'evidence_reference' => $v['evidence_reference'], 'reason' => $v['reason'], 'observed_pieces' => (int) $v['observed_pieces'], 'reviewed_by' => $u->id, 'reviewed_at' => now()->toIso8601String()];
            DB::table('recall_targets')->where('id', $target)->update($d);
            app(FoodSafety::class)->forget($org);
            Audit::record($u->id, $org, 'food.coordinated_'.$v['action'], $target, (array) $t, ['campaign_id' => $id, ...$d]);

            return ['id' => $target, 'campaign_id' => $id, 'organization_id' => $org, ...$d];
        });
    }

    public function close(User $u, string $org, string $id, array $v): array
    {
        $this->manager($u, $org);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'recall.close:'.$id, $v, function () use ($u, $org, $id, $v) {
            $c = DB::table('recall_campaigns')->where('organization_id', $org)->lockForUpdate()->find($id);
            abort_unless($c && $c->status === 'active' && (int) $c->version === (int) $v['version'], 409);
            $d = ['id' => $id, 'organization_id' => $org, 'status' => 'closed', 'version' => (int) $c->version + 1, 'closed_at' => now()->toIso8601String()];
            DB::table('recall_campaigns')->where('id', $id)->update(['status' => 'closed', 'version' => $d['version'], 'closed_at' => $d['closed_at']]);
            Audit::record($u->id, $org, 'food.coordination_closed', $id, null, [...$d, 'reason' => $v['reason']]);

            return $d;
        });
    }
}
