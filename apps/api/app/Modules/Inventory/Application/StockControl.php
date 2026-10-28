<?php

namespace App\Modules\Inventory\Application;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Inventory\Domain\StockBucket;
use App\Modules\Inventory\Domain\StockTransformation;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Inventory\Infrastructure\InventoryCount;
use App\Modules\Inventory\Infrastructure\InventoryProcess;
use App\Shared\Application\Operations;
use Illuminate\Support\Str;

final class StockControl
{
    public function __construct(private StockAccess $access, private StockLedger $ledger, private Operations $operations) {}

    private function batch(string $store, string $id, int $revision): InventoryBatch
    {
        $batch = InventoryBatch::where('store_id', $store)->lockForUpdate()->findOrFail($id);
        abort_unless($batch->stock_revision === $revision, 409, 'Snapshot stok berubah. Muat ulang dan hitung/periksa kembali sebelum melanjutkan.');

        return $batch;
    }

    private function balances(InventoryBatch $batch): array
    {
        return $batch->only(['usable_pieces', 'opened_pieces', 'ready_pieces', 'quarantined_pieces', 'waste_pieces', 'stock_revision']);
    }

    public function process(User $user, string $store, array $v): array
    {
        $s = $this->access->staff($user, $store, true);

        return $this->operations->run($s->organization_id, $user->id, $v['operation_id'], 'inventory.process:'.$store, $v, function () use ($user, $store, $s, $v) {
            $batch = $this->batch($store, $v['batch_id'], (int) $v['expected_revision']);
            abort_unless($batch->condition === 'frozen', 422, 'Buka dan kukus tahap ini hanya untuk batch beku.');
            $plan = $v['kind'] === 'open' ? StockTransformation::open($batch->pack_size, (int) $v['quantity_packs']) : StockTransformation::cook((int) $v['input_pieces'], (int) $v['output_pieces']);
            $before = $this->balances($batch);
            $id = (string) Str::ulid();
            $after = $this->ledger->apply($batch, $user->id, $plan->changes, 'inventory_'.$v['kind'], $id, $v['reason']);
            $record = new InventoryProcess(['organization_id' => $s->organization_id, 'store_id' => $store, 'inventory_batch_id' => $batch->id, 'actor_id' => $user->id, 'kind' => $v['kind'], 'pack_size' => $batch->pack_size, 'input_pieces' => $plan->input, 'output_pieces' => $plan->output, 'waste_pieces' => $plan->waste, 'reason' => $v['reason'], 'before' => $before, 'after' => $this->balances($after), 'created_at' => now()]);
            $record->id = $id;
            $record->save();
            Audit::record($user->id, $s->organization_id, 'inventory.processed', $id, $before, $record->toArray());

            return $record->toArray();
        });
    }

    public function propose(User $user, string $store, array $v): array
    {
        $s = $this->access->staff($user, $store, true);

        return $this->operations->run($s->organization_id, $user->id, $v['operation_id'], 'inventory.count:'.$store, $v, function () use ($user, $store, $s, $v) {
            $batch = $this->batch($store, $v['batch_id'], (int) $v['expected_revision']);
            $bucket = StockBucket::from($v['bucket']);
            abort_unless($bucket->countable(), 422, 'Susut kumulatif tidak dapat diubah melalui opname.');
            $expected = $batch->{$bucket->column()};
            $counted = (int) $v['counted_pieces'];
            if ($bucket === StockBucket::Sealed) {
                abort_unless($counted % $batch->pack_size === 0, 422, 'Jumlah fisik kemasan utuh harus kelipatan isi kemasan historis.');
            }
            if ($v['purpose'] === 'waste') {
                abort_unless($counted < $expected, 422, 'Pengajuan susut hanya boleh mengurangi bucket yang dihitung.');
            }
            $record = InventoryCount::create(['organization_id' => $s->organization_id, 'store_id' => $store, 'inventory_batch_id' => $batch->id, 'created_by' => $user->id, 'purpose' => $v['purpose'], 'bucket' => $bucket->value, 'status' => 'pending', 'version' => 1, 'snapshot_revision' => $batch->stock_revision, 'expected_pieces' => $expected, 'counted_pieces' => $counted, 'difference_pieces' => $counted - $expected, 'reason' => $v['reason']]);
            Audit::record($user->id, $s->organization_id, 'stock_count.created', $record->id, null, $record->toArray());

            return $record->toArray();
        });
    }

    public function review(User $user, string $id, string $action, array $v): array
    {
        $initial = InventoryCount::findOrFail($id);
        $store = $this->access->staff($user, $initial->store_id, true);
        if ($action === 'cancel') {
            abort_unless((int) $initial->created_by === $user->id, 403, 'Hanya pengusul yang dapat membatalkan opname.');
        } else {
            $this->access->reviewer($user, $store, (int) $initial->created_by);
        }

        return $this->operations->run($store->organization_id, $user->id, $v['operation_id'], 'inventory.count.'.$action.':'.$id, $v, function () use ($user, $id, $action, $v) {
            $count = InventoryCount::lockForUpdate()->findOrFail($id);
            abort_unless($count->status === 'pending' && $count->version === (int) $v['version'], 409, 'Status atau versi pengajuan berubah.');
            $before = $count->toArray();
            if ($action === 'approve') {
                $batch = $this->batch($count->store_id, $count->inventory_batch_id, $count->snapshot_revision);
                $bucket = StockBucket::from($count->bucket);
                abort_unless($batch->{$bucket->column()} === $count->expected_pieces, 409, 'Saldo stok tidak sesuai snapshot. Hitung ulang.');
                $changes = [$bucket->value => $count->difference_pieces];
                if ($count->purpose === 'waste') {
                    $changes['waste'] = -$count->difference_pieces;
                }
                $this->ledger->apply($batch, $user->id, $changes, $count->purpose === 'waste' ? 'stock_waste' : 'stock_count', $id, $count->reason.' | Review: '.$v['review_note']);
            }
            $status = match ($action) {
                'approve' => 'approved','reject' => 'rejected','cancel' => 'cancelled'
            };
            $count->fill(['status' => $status, 'reviewed_by' => $user->id, 'review_note' => $v['review_note'], 'reviewed_at' => now(), 'version' => $count->version + 1]);
            $count->save();
            Audit::record($user->id, $count->organization_id, 'stock_count.'.$action, $id, $before, $count->toArray());

            return $count->toArray();
        });
    }
}
