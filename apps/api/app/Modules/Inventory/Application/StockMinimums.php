<?php

namespace App\Modules\Inventory\Application;

use App\Models\User;
use App\Modules\Catalog\Infrastructure\Product;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Store;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StockMinimums
{
    public const MANAGERS = ['owner', 'admin', 'partner_lead', 'store_manager'];

    public function __construct(private Access $access, private Operations $operations) {}

    public function report(User $user, string $store, array $filters): array
    {
        $s = $this->access->operatingStore($user, $store);
        // Read projection only: no price columns and no mutations to Catalog/Identity.
        $totals = DB::table('inventory_batches')->where('store_id', $store)->groupBy('product_id')
            ->select('product_id')->selectRaw('SUM(usable_pieces) AS usable_pieces, SUM(opened_pieces) AS opened_pieces, SUM(ready_pieces) AS ready_pieces');
        $q = DB::table('products as p')->where('p.organization_id', $s->organization_id)->where('p.active', true)
            ->leftJoinSub($totals, 'b', 'b.product_id', '=', 'p.id')
            ->leftJoin('inventory_minimums as m', function ($join) use ($store) {
                $join->on('m.product_id', '=', 'p.id')->where('m.store_id', $store);
            })
            ->select('p.id as product_id', 'p.sku', 'p.name', 'p.condition', 'm.minimum_pieces')
            ->selectRaw('COALESCE(m.version, 0) AS version, COALESCE(b.usable_pieces, 0) AS usable_pieces, COALESCE(b.opened_pieces, 0) AS opened_pieces, COALESCE(b.ready_pieces, 0) AS ready_pieces, COALESCE(b.usable_pieces, 0) + COALESCE(b.opened_pieces, 0) + COALESCE(b.ready_pieces, 0) AS available_pieces');
        if (($filters['q'] ?? '') !== '') {
            $q->where(function ($query) use ($filters) {
                $query->where('p.sku', 'like', '%'.$filters['q'].'%')->orWhere('p.name', 'like', '%'.$filters['q'].'%');
            });
        }
        if (isset($filters['product_id'])) {
            $q->where('p.id', $filters['product_id']);
        }
        if ($filters['low_only'] ?? false) {
            $q->whereNotNull('m.minimum_pieces')->whereRaw('COALESCE(b.usable_pieces, 0) + COALESCE(b.opened_pieces, 0) + COALESCE(b.ready_pieces, 0) < m.minimum_pieces');
        }
        $page = $q->orderBy('p.sku')->orderBy('p.id')->paginate(50, ['*'], 'page', isset($filters['product_id']) ? 1 : null);
        $page->through(function ($row) {
            $r = (array) $row;
            foreach (['version', 'usable_pieces', 'opened_pieces', 'ready_pieces', 'available_pieces'] as $key) {
                $r[$key] = (int) $r[$key];
            }
            $r['minimum_pieces'] = $r['minimum_pieces'] === null ? null : (int) $r['minimum_pieces'];
            $r['is_low'] = $r['minimum_pieces'] !== null && $r['available_pieces'] < $r['minimum_pieces'];
            $r['shortfall_pieces'] = $r['is_low'] ? $r['minimum_pieces'] - $r['available_pieces'] : 0;

            return $r;
        });

        return [...$page->toArray(), 'store' => $s->only(['id', 'name']), 'can_manage' => in_array($this->access->role($user, $s->organization_id)?->value, self::MANAGERS, true), 'observed_at' => now()->toIso8601String()];
    }

    public function detail(User $user, string $store, string $product): array
    {
        // Use the same scoped, price-free projection. Exact ID survives SKU renames.
        $report = $this->report($user, $store, ['product_id' => $product]);
        abort_unless(count($report['data']) === 1, 404);

        return ['data' => $report['data'][0], 'store' => $report['store'], 'can_manage' => $report['can_manage'], 'observed_at' => $report['observed_at']];
    }

    public function configure(User $user, string $store, string $product, array $v): array
    {
        $s = $this->access->operatingStore($user, $store, self::MANAGERS);

        return $this->operations->run($s->organization_id, $user->id, $v['operation_id'], 'inventory.minimum:'.$store.':'.$product, $v, function () use ($user, $store, $product, $s, $v) {
            // Serialize initial configuration as well as later versioned changes.
            Store::whereKey($store)->lockForUpdate()->firstOrFail();
            Product::where('organization_id', $s->organization_id)->where('active', true)->lockForUpdate()->findOrFail($product);
            $before = DB::table('inventory_minimums')->where('store_id', $store)->where('product_id', $product)->lockForUpdate()->first();
            abort_unless((int) ($before->version ?? 0) === (int) $v['version'], 409, 'Ambang berubah. Muat ulang sebelum menyimpan.');
            $row = ['id' => $before->id ?? (string) Str::ulid(), 'store_id' => $store, 'product_id' => $product, 'minimum_pieces' => isset($v['minimum_pieces']) ? (int) $v['minimum_pieces'] : null, 'version' => (int) $v['version'] + 1, 'updated_by' => $user->id, 'reason' => $v['reason'], 'updated_at' => now()];
            if ($before) {
                DB::table('inventory_minimums')->where('id', $before->id)->update($row);
            } else {
                DB::table('inventory_minimums')->insert([...$row, 'created_at' => now()]);
            }
            $result = (array) DB::table('inventory_minimums')->where('id', $row['id'])->first();
            Audit::record($user->id, $s->organization_id, 'inventory.minimum_configured', $row['id'], $before ? (array) $before : null, $result);

            return $result;
        });
    }
}
