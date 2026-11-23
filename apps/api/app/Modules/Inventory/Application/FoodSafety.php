<?php

namespace App\Modules\Inventory\Application;

use App\Modules\Accounting\Application\ConsignorCosting;
use App\Modules\Accounting\Application\DistributionCosting;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FoodSafety
{
    private array $policies = [];

    private array $recalls = [];

    private array $coordinated = [];

    public function forget(string $org): void
    {
        unset($this->policies[$org],$this->recalls[$org],$this->coordinated[$org]);
    }

    public function policy(string $org): mixed
    {
        if (! array_key_exists($org, $this->policies)) {
            $this->policies[$org] = DB::table('food_sops')->where('organization_id', $org)->where('enabled', true)->first();
            $this->recalls[$org] = DB::table('batch_recalls')->where('organization_id', $org)->where('status', 'active')->get(['product_id', 'batch_code'])->mapWithKeys(fn ($r) => [$r->product_id.'|'.$r->batch_code => true])->all();
        }

        return $this->policies[$org];
    }

    public function eligible(InventoryBatch $b, string $bucket): bool
    {
        if (app(ConsignorCosting::class)->blocked($b->organization_id, $b->id)) {
            return false;
        }
        if (app(DistributionCosting::class)->blocked($b->organization_id, $b->id)) {
            return false;
        }
        $p = $this->policy($b->organization_id);
        if (! array_key_exists($b->organization_id, $this->coordinated)) {
            $this->coordinated[$b->organization_id] = DB::table('recall_targets')->where('organization_id', $b->organization_id)->where('status', 'held')->pluck('inventory_batch_id')->flip()->all();
        }
        if (isset($this->coordinated[$b->organization_id][$b->id])) {
            return false;
        }
        if (isset($this->recalls[$b->organization_id][$b->product_id.'|'.$b->batch_code])) {
            return false;
        }
        if (! $p) {
            return true;
        }if ($p->block_expired && $b->expires_on->format('Y-m-d') < now('Asia/Jakarta')->format('Y-m-d')) {
            return false;
        }if ($bucket === 'ready' && $p->ready_life_minutes !== null) {
            $lots = DB::table('inventory_ready_lots')->where('inventory_batch_id', $b->id)->where('remaining_pieces', '>', 0)->get(['ready_expires_at']);
            if ($lots->isNotEmpty()) {
                return $lots->contains(fn ($l) => $l->ready_expires_at && CarbonImmutable::parse($l->ready_expires_at)->gt(now()));
            }
            if (! $b->ready_expires_at || $b->ready_expires_at->lte(now())) {
                return false;
            }
        }

        return true;
    }

    public function assertEligible(InventoryBatch $b, string $bucket): void
    {
        abort_unless($this->eligible($b, $bucket), 409, 'Batch diblokir review biaya pengganti, recall/kedaluwarsa atau masa simpan siap jual belum sah/berakhir. Tinjau stok dan SOP.');
    }

    public function lockCandidates(string $store, array $products): void
    {
        $org = DB::table('stores')->where('id', $store)->value('organization_id');
        $p = $org ? $this->policy($org) : null;
        if ($p?->fefo === 'enforce') {
            InventoryBatch::where('store_id', $store)->whereIn('product_id', $products)->orderBy('id')->lockForUpdate()->get();
        }
    }

    public function allocations(string $store, array $allocations): void
    {
        $org = DB::table('stores')->where('id', $store)->value('organization_id');
        $p = $org ? $this->policy($org) : null;
        if ($p?->fefo !== 'enforce') {
            return;
        }$groups = [];
        foreach ($allocations as $a) {
            $groups[$a['product_id'].'|'.$a['bucket'].'|'.$a['condition'].'|'.($a['bucket'] === 'usable' ? ($a['pack_size'] ?? $a['pieces_per_unit'] ?? '') : '')][] = $a;
        }foreach ($groups as $group) {
            $first = $group[0];
            $used = [];
            foreach ($group as $a) {
                $used[$a['batch_id']] = ($used[$a['batch_id']] ?? 0) + $a['pieces'];
            }$batches = InventoryBatch::where('store_id', $store)->where('product_id', $first['product_id'])->where('condition', $first['condition'])->get()->filter(fn ($b) => $this->eligible($b, $first['bucket']) && ($first['bucket'] !== 'usable' || $b->pack_size === ($first['pack_size'] ?? $first['pieces_per_unit'] ?? $b->pack_size)) && $b->{$first['bucket'].'_pieces'} > 0)->sort(fn ($a, $b) => $this->compare($a, $b, $first['bucket']));
            $olderLeft = false;
            foreach ($batches as $b) {
                $n = $used[$b->id] ?? 0;
                if ($n > 0) {
                    abort_if($olderLeft, 409, 'SOP FEFO: gunakan stok layak dengan batas simpan paling awal terlebih dahulu.');
                }if ($b->{$first['bucket'].'_pieces'} > $n) {
                    $olderLeft = true;
                }
            }
        }
    }

    private function deadline(InventoryBatch $b, string $bucket): int
    {
        $packaging = CarbonImmutable::parse($b->expires_on->format('Y-m-d'), 'Asia/Jakarta')->endOfDay()->timestamp;
        if ($bucket === 'ready' && $b->ready_expires_at) {
            $ready = $b->ready_expires_at->timestamp;

            return $this->policy($b->organization_id)?->block_expired ? min($packaging, $ready) : $ready;
        }

        return $packaging;
    }

    private function compare(InventoryBatch $a, InventoryBatch $b, string $bucket): int
    {
        return ($this->deadline($a, $bucket) <=> $this->deadline($b, $bucket)) ?: strcmp($a->id, $b->id);
    }

    public function suggestions(string $store, string $product, string $bucket, string $condition, ?int $packSize): array
    {
        $org = DB::table('stores')->where('id', $store)->value('organization_id');
        $p = $this->policy($org);
        if (! $p || $p->fefo === 'off') {
            return ['store_id' => $store, 'product_id' => $product, 'bucket' => $bucket, 'mode' => 'off', 'data' => []];
        }
        $batches = InventoryBatch::where('store_id', $store)->where('product_id', $product)->where('condition', $condition)
            ->when($bucket === 'usable', fn ($q) => $q->where('pack_size', $packSize))
            ->where($bucket.'_pieces', '>', 0)->limit(2001)->get();
        abort_if($batches->count() > 2000, 422, 'Terlalu banyak batch; gunakan pengelolaan batch sebelum meminta saran.');
        $rows = $batches->filter(fn ($b) => $this->eligible($b, $bucket))->sort(fn ($a, $b) => $this->compare($a, $b, $bucket))->values()
            ->map(fn ($b) => ['id' => $b->id, 'store_id' => $store, 'product_id' => $product, 'batch_code' => $b->batch_code,
                'pack_size' => $b->pack_size, 'available_pieces' => (int) $b->{$bucket.'_pieces'},
                'deadline' => CarbonImmutable::createFromTimestampUTC($this->deadline($b, $bucket))->toIso8601String()])->all();

        return ['store_id' => $store, 'product_id' => $product, 'bucket' => $bucket, 'mode' => $p->fefo, 'data' => $rows];
    }

    public function cooked(InventoryBatch $batch, int $previousReady, int $output, ?string $processId = null): void
    {
        if ($output < 1) {
            return;
        }
        $p = $this->policy($batch->organization_id);
        $now = now();
        $hasLots = DB::table('inventory_ready_lots')->where('inventory_batch_id', $batch->id)->where('remaining_pieces', '>', 0)->exists();
        if ($previousReady > 0 && ! $hasLots) {
            DB::table('inventory_ready_lots')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'organization_id' => $batch->organization_id,
                'store_id' => $batch->store_id,
                'inventory_batch_id' => $batch->id,
                'inventory_process_id' => null,
                'cooked_at' => $now,
                'ready_expires_at' => $batch->ready_expires_at,
                'initial_pieces' => $previousReady,
                'remaining_pieces' => $previousReady,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $limit = ($p && $p->ready_life_minutes !== null) ? $now->copy()->addMinutes((int) $p->ready_life_minutes) : null;
        DB::table('inventory_ready_lots')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'organization_id' => $batch->organization_id,
            'store_id' => $batch->store_id,
            'inventory_batch_id' => $batch->id,
            'inventory_process_id' => $processId,
            'cooked_at' => $now,
            'ready_expires_at' => $limit,
            'initial_pieces' => $output,
            'remaining_pieces' => $output,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $earliest = $this->earliestReadyExpiry($batch->id);
        if ($earliest !== null || $batch->ready_expires_at !== null) {
            $batch->forceFill(['ready_expires_at' => $earliest])->save();
        }
    }

    public function depleteReadyLots(InventoryBatch $batch, int $pieces, string $sourceType): ?CarbonImmutable
    {
        $lots = DB::table('inventory_ready_lots')
            ->where('inventory_batch_id', $batch->id)
            ->where('remaining_pieces', '>', 0)
            ->orderByRaw('CASE WHEN ready_expires_at IS NULL THEN 1 ELSE 0 END, ready_expires_at ASC, id ASC')
            ->lockForUpdate()
            ->get();
        if ($lots->isEmpty()) {
            return $batch->ready_expires_at ? CarbonImmutable::instance($batch->ready_expires_at) : null;
        }
        $p = $this->policy($batch->organization_id);
        $now = now();
        $strict = $sourceType === 'pos_sale' && $p && $p->ready_life_minutes !== null;
        $candidates = $strict
            ? $lots->filter(fn ($l) => $l->ready_expires_at && CarbonImmutable::parse($l->ready_expires_at)->gt($now))
            : $lots;
        abort_if($candidates->sum('remaining_pieces') < $pieces, 409, 'Stok siap jual yang masih dalam masa simpan tidak mencukupi.');
        $need = $pieces;
        foreach ($candidates as $lot) {
            if ($need <= 0) {
                break;
            }
            $take = min($need, (int) $lot->remaining_pieces);
            DB::table('inventory_ready_lots')->where('id', $lot->id)->update([
                'remaining_pieces' => (int) $lot->remaining_pieces - $take,
                'updated_at' => $now,
            ]);
            $need -= $take;
        }

        return $this->earliestReadyExpiry($batch->id);
    }

    private function earliestReadyExpiry(string $batchId): ?CarbonImmutable
    {
        $min = DB::table('inventory_ready_lots')
            ->where('inventory_batch_id', $batchId)
            ->where('remaining_pieces', '>', 0)
            ->whereNotNull('ready_expires_at')
            ->orderBy('ready_expires_at')
            ->value('ready_expires_at');

        return $min ? CarbonImmutable::parse($min) : null;
    }
}
