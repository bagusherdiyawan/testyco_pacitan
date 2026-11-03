<?php

namespace App\Modules\Inventory\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Shared\Application\BoundedExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LedgerHistory
{
    public const SOURCES = ['replacement_dispatch', 'replacement_receipt', 'warehouse_intake', 'shipment_dispatch', 'shipment_receipt', 'inventory_open', 'inventory_cook', 'stock_count', 'stock_waste', 'pos_sale'];

    public function __construct(private Access $access) {}

    private function scope(User $user, string $store, array $filters): array
    {
        $source = $this->access->store($user, $store, StockAccess::STAFF);
        $query = DB::table('stock_entries as e')->join('inventory_batches as b', 'b.id', '=', 'e.inventory_batch_id')
            ->where('e.store_id', $store)->where('b.store_id', $store)
            ->select('e.*', 'b.sku', 'b.name', 'b.batch_code', 'b.condition', 'b.pack_size', 'b.expires_on');
        if (isset($filters['batch_id'])) {
            InventoryBatch::where('store_id', $store)->findOrFail($filters['batch_id']);
            $query->where('e.inventory_batch_id', $filters['batch_id']);
        }
        if (isset($filters['source_type'])) {
            $query->where('e.source_type', $filters['source_type']);
        }
        if (isset($filters['bucket'])) {
            $query->where('e.bucket', $filters['bucket']);
        }
        if (($filters['q'] ?? '') !== '') {
            $query->where(fn ($q) => $q->where('b.sku', 'like', '%'.$filters['q'].'%')->orWhere('b.name', 'like', '%'.$filters['q'].'%')->orWhere('b.batch_code', 'like', '%'.$filters['q'].'%'));
        }
        $from = $to = null;
        if (isset($filters['from'], $filters['to'])) {
            $from = CarbonImmutable::parse($filters['from'])->utc();
            $to = CarbonImmutable::parse($filters['to'])->utc();
            abort_if($to->timestamp - $from->timestamp > 31 * 86400, 422, 'Rentang riwayat maksimal 31 hari.');
            $query->where('e.created_at', '>=', $from)->where('e.created_at', '<', $to);
        }

        return [$query, $source, $from, $to];
    }

    private function entry(object $row): array
    {
        return [...(array) $row, 'quantity_pieces' => (int) $row->quantity_pieces, 'pack_size' => (int) $row->pack_size, 'actor_id' => (int) $row->actor_id, 'occurred_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601String()];
    }

    public function read(User $user, string $store, array $filters): array
    {
        [$query, $source, $from, $to] = $this->scope($user, $store, $filters);
        $page = $query->orderByDesc('e.created_at')->orderByDesc('e.id')->paginate(100)->through(fn ($row) => $this->entry($row));

        return [...$page->toArray(), 'store' => $source->only(['id', 'name', 'active']), 'observed_at' => now()->toIso8601String(), 'filters' => [...$filters, 'from' => $from?->toIso8601String(), 'to' => $to?->toIso8601String()]];
    }

    public function export(User $user, string $store, array $filters): array
    {
        [$query, $source, $from, $to] = $this->scope($user, $store, $filters);
        abort_unless($from && $to, 422, 'Ekspor riwayat stok membutuhkan periode dengan timezone.');
        $rows = BoundedExport::collect($query->orderByDesc('e.created_at')->orderByDesc('e.id')->limit(BoundedExport::MAX_ROWS + 1)->cursor()->map(fn ($row) => $this->entry($row)), 'riwayat stok');

        return ['data' => $rows, 'row_count' => count($rows), 'store' => $source->only(['id', 'name', 'active']), 'observed_at' => now()->toIso8601String(), 'filters' => [...$filters, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String()]];
    }
}
