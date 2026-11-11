<?php

namespace App\Modules\Accounting\Application;

use App\Models\User;
use App\Modules\Commercial\Application\ConsignmentAutomation;
use App\Modules\Commercial\Infrastructure\CommercialAgreement;
use App\Modules\Identity\Application\Audit;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleRefund;
use App\Shared\Domain\Proportion;
use App\Shared\Domain\Rupiah;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Physical consignor cost is private owner evidence, never contractual margin cost or retailer inventory. */
final class ConsignorCosting
{
    private function require(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new DomainException($message);
        }
    }

    private function policy(string $org, ?string $id = null): ?object
    {
        return app(DistributionCosting::class)->policy($org, $id);
    }

    public function held(string $batch): int
    {
        return (int) DB::table('consignor_pools')->where('inventory_batch_id', $batch)->value('quantity');
    }

    public function blocked(string $org, string $batch): bool
    {
        return DB::table('consignor_valuations as v')->where('v.partner_id', $org)->where('v.status', 'review_required')->where(function ($q) use ($batch) {
            $q->whereExists(fn ($q) => $q->selectRaw('1')->from('stock_entries as e')->join('shipment_lines as l', 'l.id', '=', 'e.source_id')->join('shipments as s', 's.id', '=', 'l.shipment_id')->where('e.source_type', 'shipment_receipt')->whereColumn('s.supply_order_id', 'v.order_id')->where('e.inventory_batch_id', $batch))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('stock_entries as e')->join('replacement_lines as l', 'l.id', '=', 'e.source_id')->join('replacement_shipments as s', 's.id', '=', 'l.replacement_shipment_id')->join('shipment_discrepancies as c', 'c.id', '=', 's.case_id')->where('e.source_type', 'replacement_receipt')->whereColumn('c.supply_order_id', 'v.order_id')->where('e.inventory_batch_id', $batch));
        })->exists();
    }

    public function automatic(CommercialAgreement $g, int $actor): void
    {
        if ($g->kind !== 'consignment' || $g->status !== 'accepted' || ! ($p = $this->policy($g->organization_id))) {
            return;
        }
        // Automatic capture is prospective. Older dispatches without a financial source remain legacy review.
        $sources = DB::table('shipment_lines as l')->join('shipments as s', 's.id', '=', 'l.shipment_id')->where('s.supply_order_id', $g->supply_order_id)->pluck('l.id')->all();
        $replacements = DB::table('replacement_lines as l')->join('replacement_shipments as s', 's.id', '=', 'l.replacement_shipment_id')->join('shipment_discrepancies as c', 'c.id', '=', 's.case_id')->where('c.supply_order_id', $g->supply_order_id)->pluck('l.id')->all();
        foreach (['shipment_dispatch' => $sources, 'replacement_dispatch' => $replacements] as $type => $ids) {
            if (! $ids) {
                continue;
            }
            $costs = DB::table('inventory_cost_entries')->where('organization_id', $g->organization_id)->where('source_type', $type)->whereIn('source_id', $ids)->get();
            $proofs = DB::table('accounting_events')->where('organization_id', $g->organization_id)->where('source_type', 'stock_review')->whereIn('source_id', $costs->pluck('id'))->count();
            if ($costs->count() !== count($ids) || $proofs !== count($ids)) {
                return;
            }
        }
        $this->enable($g, $p, $actor);
    }

    public function command(User $u, string $org, string $action, array $v): array
    {
        if ($action === 'consignor_dispose') {
            return $this->dispose($u, $org, $v);
        }
        if ($action === 'consignor_enable') {
            $g = CommercialAgreement::where('organization_id', $org)->where('kind', 'consignment')->findOrFail($v['agreement_id']);
            SupplyOrder::whereKey($g->supply_order_id)->lockForUpdate()->firstOrFail();
            app(AccountingAccess::class)->manage($u, $org);
            $p = $this->policy($org);
            abort_unless($g->status === 'accepted' && $p, 409, 'Perjanjian konsinyasi dan pemetaan pemilik wajib aktif.');
            abort_if(DB::table('consignor_valuations')->where('agreement_id', $g->id)->exists(), 409, 'Gunakan antrean pemeriksaan yang sudah ada.');

            $out = (array) $this->enable($g, $p, $u->id);
            Audit::record($u->id, $org, 'accounting.consignor_enable_requested', $out['id'], null, ['agreement_id' => $g->id, 'note' => $v['note']]);

            return $out;
        }
        $r = DB::table('consignor_valuations')->where('organization_id', $org)->where('id', $v['id'])->firstOrFail();
        SupplyOrder::whereKey($r->order_id)->lockForUpdate()->firstOrFail();
        app(AccountingAccess::class)->manage($u, $org);
        abort_unless($r->status === 'review_required' && $r->version === $v['version'], 409);

        $out = (array) $this->attempt($r->id);
        Audit::record($u->id, $org, 'accounting.consignor_retry_requested', $r->id, null, ['version' => $v['version'], 'note' => $v['note']]);

        return $out;
    }

    private function enable(CommercialAgreement $g, object $p, int $actor): object
    {
        $id = (string) Str::ulid();
        DB::table('consignor_valuations')->insert(['id' => $id, 'organization_id' => $g->organization_id, 'partner_id' => $g->partner_id, 'agreement_id' => $g->id, 'order_id' => $g->supply_order_id, 'policy_id' => $p->id, 'status' => 'pending', 'version' => 1, 'actor_id' => $actor, 'activated_on' => now('Asia/Jakarta')->format('Y-m-d'), 'created_at' => now(), 'updated_at' => now()]);

        return $this->attempt($id);
    }

    private function attempt(string $id): object
    {
        $r = DB::table('consignor_valuations')->where('id', $id)->lockForUpdate()->firstOrFail();
        try {
            DB::transaction(function () use ($r) {
                $g = CommercialAgreement::findOrFail($r->agreement_id);
                $order = SupplyOrder::findOrFail($r->order_id);
                $this->require($g->status === 'accepted', 'Perjanjian konsinyasi belum aktif.');
                $this->require(! DB::table('offline_permits')->where('store_id', $order->store_id)->whereNull('closed_at')->exists(), 'Tutup izin offline dan selesaikan antrean sebelum mengaktifkan valuasi; transaksi belum tersinkron tidak boleh diimpor.');
                app(ConsignmentAutomation::class)->lockOrderBatches($order->id);
                app(ConsignmentAutomation::class)->assertFreshOrder($order->id);
                $lines = DB::table('shipment_lines as l')->join('shipments as s', 's.id', '=', 'l.shipment_id')->where('s.supply_order_id', $r->order_id)->orderBy('l.id')->limit(1001)->get(['l.*', 's.received_at']);
                $extra = DB::table('replacement_lines as l')->join('replacement_shipments as s', 's.id', '=', 'l.replacement_shipment_id')->join('shipment_discrepancies as c', 'c.id', '=', 's.case_id')->where('c.supply_order_id', $r->order_id)->orderBy('l.id')->limit(1001)->get(['l.*', 's.received_at']);
                $this->require($lines->count() + $extra->count() <= 1000 && strlen(json_encode([$lines, $extra])) <= 8 * 1024 * 1024, 'Bukti aktivasi konsinyasi melebihi1000kiriman/8MiB.');
                foreach ($lines as $l) {
                    $this->lot($r, 'shipment_dispatch', $l->id, $l->supply_order_line_id);
                }
                foreach ($extra as $l) {
                    $parent = $lines->firstWhere('id', $l->original_shipment_line_id);
                    $this->require((bool) $parent, 'Asal pengganti tidak ditemukan.');
                    $this->lot($r, 'replacement_dispatch', $l->id, $parent->supply_order_line_id);
                }
                $this->adopt($r);
                foreach ($lines as $l) {
                    if ($l->received_at) {
                        $this->received($order, 'shipment_dispatch', $l->id, ($l->good_packs + $l->damaged_packs) * $l->pack_size, $r->actor_id);
                    }
                }
                foreach ($extra as $l) {
                    if ($l->received_at) {
                        $this->received($order, 'replacement_dispatch', $l->id, ($l->good_packs + $l->damaged_packs) * $l->pack_size, $r->actor_id);
                    }
                }
                DB::table('consignor_valuations')->where('id', $r->id)->update(['status' => 'active', 'reason' => null, 'version' => $r->version + 1, 'updated_at' => now()]);
            });
        } catch (DomainException $e) {
            DB::table('consignor_valuations')->where('id', $r->id)->update(['status' => 'review_required', 'reason' => $e->getMessage(), 'version' => $r->version + 1, 'updated_at' => now()]);
        }
        $out = DB::table('consignor_valuations')->where('id', $r->id)->firstOrFail();
        Audit::record($r->actor_id, $r->organization_id, 'accounting.consignor_valuation', $r->id, null, ['agreement_id' => $r->agreement_id, 'status' => $out->status, 'reason' => $out->reason]);

        return $out;
    }

    private function lot(object $r, string $type, string $line, string $orderLine): object
    {
        $prior = DB::table('consignor_lots')->where('source_type', $type)->where('source_id', $line)->first();
        if ($prior) {
            return $prior;
        }
        $costing = app(DistributionCosting::class);
        $source = $costing->source($r->organization_id, $type, $line);
        $event = $source ? $costing->review($source) : null;
        $policy = $this->policy($r->organization_id, $r->policy_id);
        $original = $event ? $this->policy($r->organization_id, $event->policy_id) : null;
        $this->require($source && $source->quantity_delta < 0 && ($source->value_delta === null || $source->value_delta <= 0) && $event && $event->status === 'review_required' && $policy && $original && $costing->matching($policy, $original, ['finished', 'cogs', 'loss']), 'Biaya/pemetaan dispatch sebelum aktivasi, berubah atau sudah direkonsiliasi; pemeriksaan sumber diperlukan.');
        $id = (string) Str::ulid();
        $value = $source->value_delta === null ? null : -(int) $source->value_delta;
        DB::table('consignor_lots')->insert(['id' => $id, 'valuation_id' => $r->id, 'source_type' => $type, 'source_id' => $line, 'source_cost_entry_id' => $source->id, 'order_line_id' => $orderLine, 'quantity' => -(int) $source->quantity_delta, 'value' => $value, 'received_quantity' => 0, 'received_value' => 0, 'settled_missing_quantity' => 0, 'settled_missing_value' => 0, 'receipt_confirmed' => false, 'created_at' => now(), 'updated_at' => now()]);
        if ($value !== null) {
            $costing->cover($r->organization_id, $type, $line, 'Aset konsinyasi pemasok tetap dimiliki; ditelusuri pada lot '.$id);
        }

        return DB::table('consignor_lots')->where('id', $id)->firstOrFail();
    }

    public function dispatched(SupplyOrder $order, string $type, string $line, string $orderLine): void
    {
        $r = DB::table('consignor_valuations')->where('order_id', $order->id)->first();
        if (! $r) {
            return;
        }
        abort_unless($r->status === 'active' && CommercialAgreement::whereKey($r->agreement_id)->where('status', 'accepted')->exists(), 409, 'Valuasi/perjanjian konsinyasi ditahan; periksa sebelum kiriman baru.');
        try {
            $this->lot($r, $type, $line, $orderLine);
        } catch (DomainException $e) {
            abort(409, $e->getMessage());
        }
    }

    private function pool(object $r, InventoryBatch $batch, string $orderLine): object
    {
        DB::table('consignor_pools')->insertOrIgnore(['id' => (string) Str::ulid(), 'valuation_id' => $r->id, 'organization_id' => $r->organization_id, 'partner_id' => $r->partner_id, 'inventory_batch_id' => $batch->id, 'order_line_id' => $orderLine, 'quantity' => 0, 'remaining_value' => 0, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $pool = DB::table('consignor_pools')->where('inventory_batch_id', $batch->id)->lockForUpdate()->firstOrFail();
        $this->require($pool->valuation_id === $r->id && $pool->order_line_id === $orderLine && $batch->organization_id === $r->partner_id, 'Batch konsinyasi bercampur pemilik/perjanjian/baris lain.');

        return $pool;
    }

    private function adopt(object $r): void
    {
        $lots = DB::table('consignor_lots')->where('valuation_id', $r->id)->get();
        $sources = [];
        foreach ($lots as $l) {
            $sources[$l->source_type === 'shipment_dispatch' ? 'shipment_receipt' : 'replacement_receipt'][] = $l->source_id;
        }
        if (! $sources) {
            return;
        }
        $entries = DB::table('inventory_cost_entries')->where('organization_id', $r->partner_id)->where(function ($q) use ($sources) {
            foreach ($sources as $type => $ids) {
                $q->orWhere(fn ($q) => $q->where('source_type', $type)->whereIn('source_id', $ids));
            }
        })->limit(10001)->get();
        $this->require($entries->count() <= 10000 && strlen(json_encode($entries)) <= 8 * 1024 * 1024, 'Bukti custody melebihi10.000/8MiB.');
        $batches = InventoryBatch::where('organization_id', $r->partner_id)->whereIn('id', $entries->pluck('inventory_batch_id')->unique())->orderBy('id')->lockForUpdate()->get();
        $count = $bytes = 0;
        foreach ($batches as $batch) {
            $all = DB::table('inventory_cost_entries')->where('inventory_batch_id', $batch->id)->orderBy('stock_revision')->limit(10001)->get();
            $count += $all->count();
            $bytes += strlen(json_encode($all));
            $this->require($count <= 10000 && $bytes <= 8 * 1024 * 1024, 'Bukti custody melebihi10.000/8MiB.');
            $incoming = $entries->where('inventory_batch_id', $batch->id);
            $lineIds = [];
            foreach ($incoming as $e) {
                $kind = $e->source_type === 'shipment_receipt' ? 'shipment_dispatch' : 'replacement_dispatch';
                $lot = $lots->first(fn ($l) => $l->source_type === $kind && $l->source_id === $e->source_id);
                $this->require((bool) $lot, 'Asal receipt custody tidak terbukti.');
                $lineIds[$lot->order_line_id] = true;
            }
            $this->require(count($lineIds) === 1, 'Batch mencampur baris pesanan konsinyasi.');
            foreach ($all as $e) {
                $this->require($incoming->contains('id', $e->id) || ((int) $e->quantity_delta === 0 && $e->value_delta !== null && (int) $e->value_delta === 0), 'Batch mempunyai gerakan/biaya sebelum aktivasi; tidak boleh mengimpor HPP lama.');
            }
            $this->require(! DB::table('accounting_value_proposals')->where('inventory_batch_id', $batch->id)->where('status', 'approved')->exists(), 'Batch sudah dinilai sebagai aset sendiri; rekonsiliasi ownership diperlukan.');
            $reviewed = DB::table('accounting_events')->where('organization_id', $r->partner_id)->where('source_type', 'stock_review')->whereIn('source_id', $all->pluck('id'))->where('status', '!=', 'review_required')->exists();
            $this->require(! $reviewed, 'Sumber custody sudah direkonsiliasi pada buku penerima.');
            $physical = $batch->usable_pieces + $batch->opened_pieces + $batch->ready_pieces + $batch->quarantined_pieces;
            $this->require((int) $incoming->sum('quantity_delta') === $physical, 'Jumlah custody tidak konservatif.');
            $pool = $this->pool($r, $batch, array_key_first($lineIds));
            $value = 0;
            foreach ($incoming->sortBy('stock_revision') as $e) {
                $kind = $e->source_type === 'shipment_receipt' ? 'shipment_dispatch' : 'replacement_dispatch';
                $lot = DB::table('consignor_lots')->where('source_type', $kind)->where('source_id', $e->source_id)->firstOrFail();
                $cost = $this->receiveLot($lot, (int) $e->quantity_delta);
                $value = $value === null || $cost === null ? null : Rupiah::add($value, $cost);
                $this->entry($r, $pool, $e->source_type, $e->source_id.':'.$e->id, (int) $e->quantity_delta, $cost, $r->actor_id, substr($e->created_at, 0, 10));
            }
            DB::table('consignor_pools')->where('id', $pool->id)->update(['quantity' => $physical, 'remaining_value' => $value, 'version' => $pool->version + 1, 'updated_at' => now()]);
            $own = DB::table('inventory_values')->where('inventory_batch_id', $batch->id)->lockForUpdate()->firstOrFail();
            $this->require((int) $own->quantity === $physical && ($own->remaining_value === null || (int) $own->remaining_value === 0), 'Saldo penerima sudah mempunyai nilai milik sendiri.');
            $proof = (string) Str::ulid();
            DB::table('consignor_bindings')->insert(['id' => $proof, 'pool_id' => $pool->id, 'organization_id' => $r->partner_id, 'inventory_batch_id' => $batch->id, 'stock_revision' => $batch->stock_revision, 'before_quantity' => $own->quantity, 'before_value' => $own->remaining_value, 'created_at' => now()]);
            app(StockLedger::class)->apply($batch, $r->actor_id, [], 'consignment_bind', $proof);

            DB::table('accounting_events')->where('organization_id', $r->partner_id)->where('source_type', 'stock_review')->whereIn('source_id', $all->pluck('id'))->where('status', 'review_required')->update(['status' => 'reconciled', 'reason' => 'Stok konsinyasi milik pemasok; bukan aset/HPP penerima.', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
    }

    public function bind(InventoryBatch $batch, string $proof): InventoryBatch
    {
        $p = DB::table('consignor_bindings')->where('organization_id', $batch->organization_id)->where('inventory_batch_id', $batch->id)->where('id', $proof)->firstOrFail();
        $own = DB::table('inventory_values')->where('inventory_batch_id', $batch->id)->lockForUpdate()->firstOrFail();
        abort_unless(DB::transactionLevel() > 0 && ! DB::table('inventory_cost_entries')->where('source_type', 'consignment_bind')->where('source_id', $proof)->exists() && $batch->stock_revision === (int) $p->stock_revision && (int) $own->quantity === (int) $p->before_quantity && $own->remaining_value === $p->before_value && app(InventoryValuation::class)->quantity($batch) === 0, 409, 'Snapshot binding custody berubah.');
        DB::table('inventory_values')->where('inventory_batch_id', $batch->id)->update(['quantity' => 0, 'remaining_value' => 0, 'updated_at' => now()]);
        $batch->increment('stock_revision');
        DB::table('inventory_cost_entries')->insert(['id' => (string) Str::ulid(), 'organization_id' => $batch->organization_id, 'inventory_batch_id' => $batch->id, 'stock_revision' => $batch->stock_revision, 'source_type' => 'consignment_bind', 'source_id' => $proof, 'quantity_delta' => -(int) $p->before_quantity, 'value_delta' => $p->before_value === null ? null : -(int) $p->before_value, 'created_at' => now()]);

        return $batch;
    }

    private function receiveLot(object $lot, int $n): ?int
    {
        $this->require($n > 0 && $lot->received_quantity + $n <= $lot->quantity, 'Penerimaan melebihi lot konsinyasi.');
        $next = $lot->value === null ? null : Proportion::floor((int) $lot->value, (int) $lot->received_quantity + $n, (int) $lot->quantity);
        $slice = $next === null ? null : $next - (int) $lot->received_value;
        DB::table('consignor_lots')->where('id', $lot->id)->update(['received_quantity' => $lot->received_quantity + $n, 'received_value' => $next, 'updated_at' => now()]);

        return $slice;
    }

    private function entry(object $r, object $pool, string $type, string $source, int $n, ?int $value, int $actor, ?string $day = null, ?string $origin = null, ?int $offset = null): string
    {
        $id = (string) Str::ulid();
        DB::table('consignor_cost_entries')->insert(['id' => $id, 'organization_id' => $r->organization_id, 'pool_id' => $pool->id, 'inventory_batch_id' => $pool->inventory_batch_id, 'policy_id' => $r->policy_id, 'source_type' => $type, 'source_id' => $source, 'origin_id' => $origin, 'cost_offset' => $offset, 'quantity_delta' => $n, 'value_delta' => $value, 'event_on' => $day, 'actor_id' => $actor, 'created_at' => now()]);

        return $id;
    }

    public function movement(InventoryBatch $b, array $changes, string $type, string $source, int $actor, ?string $origin, ?int $offset): bool
    {
        $pool = DB::table('consignor_pools')->where('inventory_batch_id', $b->id)->lockForUpdate()->first();
        $r = null;
        $lot = null;
        if (in_array($type, ['shipment_receipt', 'replacement_receipt'], true)) {
            $dispatch = $type === 'shipment_receipt' ? 'shipment_dispatch' : 'replacement_dispatch';
            $lot = DB::table('consignor_lots')->where('source_type', $dispatch)->where('source_id', $source)->lockForUpdate()->first();
            if ($lot) {
                $r = DB::table('consignor_valuations')->where('id', $lot->valuation_id)->firstOrFail();
                $pool = $this->pool($r, $b, $lot->order_line_id);
            }
        }
        if (! $pool) {
            return false;
        }
        $r ??= DB::table('consignor_valuations')->where('id', $pool->valuation_id)->firstOrFail();
        abort_unless($r->status === 'active' && $r->partner_id === $b->organization_id, 409, 'Valuasi konsinyasi belum aktif.');
        $physical = $b->usable_pieces + $b->opened_pieces + $b->ready_pieces + $b->quarantined_pieces;
        abort_unless($physical === (int) $pool->quantity, 409, 'Batch konsinyasi bercampur stok milik penerima.');
        $n = array_sum(array_filter($changes, fn ($key) => $key !== 'waste', ARRAY_FILTER_USE_KEY));
        $cost = 0;
        $originEntry = null;
        if ($n > 0) {
            if ($lot) {
                $cost = $this->receiveLot($lot, $n);
            } elseif ($type === 'sale_refund' && $origin) {
                $old = DB::table('consignor_cost_entries')->where('pool_id', $pool->id)->where('source_type', 'pos_sale')->where('source_id', $origin)->firstOrFail();
                $start = $offset ?? 0;
                $total = -(int) $old->quantity_delta;
                abort_unless($start >= 0 && $start + $n <= $total, 409, 'Offset refund konsinyasi tidak sesuai alokasi asal.');
                foreach (DB::table('consignor_cost_entries')->where('origin_id', $old->id)->get() as $prior) {
                    abort_unless($start + $n <= $prior->cost_offset || $start >= $prior->cost_offset + $prior->quantity_delta, 409, 'Offset refund konsinyasi bertumpang tindih.');
                }
                $cost = $old->value_delta === null ? null : Proportion::floor(-(int) $old->value_delta, $start + $n, $total) - Proportion::floor(-(int) $old->value_delta, $start, $total);
                $originEntry = $old->id;
            } else {
                abort(409, 'Penerimaan lain tidak boleh mencampur custody konsinyasi.');
            }
        } elseif ($n < 0) {
            abort_unless(-$n <= $pool->quantity && ($type === 'pos_sale' || $type === 'return_dispatch' || $type === 'stock_count' || ($changes['waste'] ?? 0) >= -$n), 409, 'Deplesi konsinyasi harus penjualan, retur atau susut reviewed.');
            $cost = $pool->remaining_value === null ? null : Proportion::floor((int) $pool->remaining_value, -$n, (int) $pool->quantity);
        }
        $signed = $cost === null ? null : ($n < 0 ? -$cost : $cost);
        $after = $pool->remaining_value === null || $signed === null ? null : ($signed > 0 ? Rupiah::add((int) $pool->remaining_value, $signed) : (int) $pool->remaining_value + $signed);
        if ($pool->quantity + $n === 0) {
            $after = 0;
        }
        $sourceKey = in_array($type, ['shipment_receipt', 'replacement_receipt'], true) ? $source.':'.($b->stock_revision + 1) : $source;
        $id = $this->entry($r, $pool, $type, $sourceKey, $n, $signed, $actor, in_array($type, ['pos_sale', 'sale_refund'], true) ? null : now('Asia/Jakarta')->format('Y-m-d'), $originEntry, $offset);
        DB::table('consignor_pools')->where('id', $pool->id)->update(['quantity' => $pool->quantity + $n, 'remaining_value' => $after, 'version' => $pool->version + 1, 'updated_at' => now()]);
        // The holder has physical stock, but zero owned inventory quantity/value and no supplier unit-cost disclosure.
        DB::table('inventory_values')->updateOrInsert(['inventory_batch_id' => $b->id], ['organization_id' => $b->organization_id, 'quantity' => 0, 'remaining_value' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('inventory_cost_entries')->insert(['id' => (string) Str::ulid(), 'organization_id' => $b->organization_id, 'inventory_batch_id' => $b->id, 'stock_revision' => $b->stock_revision + 1, 'source_type' => $type, 'source_id' => $source, 'origin_id' => $origin, 'quantity_delta' => 0, 'value_delta' => 0, 'created_at' => now()]);
        if ($n < 0 && $type === 'return_dispatch') {
            DB::table('consignor_returns')->insert(['id' => (string) Str::ulid(), 'return_line_id' => $source, 'valuation_id' => $r->id, 'source_entry_id' => $id, 'quantity' => -$n, 'value' => $cost, 'received_value' => 0, 'status' => 'in_transit', 'created_at' => now(), 'updated_at' => now()]);
        } elseif ($n < 0 && $type !== 'pos_sale') {
            $this->disposition($r, $id, -$n, $cost, $actor);
        }

        return true;
    }

    public function sale(Sale $sale): void
    {
        $ids = DB::table('sale_allocations as a')->join('sale_lines as l', 'l.id', '=', 'a.sale_line_id')->where('l.sale_id', $sale->id)->pluck('a.id');
        $this->postCosts('pos_sale', $ids->all(), $sale->cashier_id, $sale->sold_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d'), $sale->sold_at);
    }

    public function refund(SaleRefund $refund, array $ids): void
    {
        $this->postCosts('sale_refund', $ids, $refund->actor_id, $refund->refunded_at->copy()->timezone('Asia/Jakarta')->format('Y-m-d'));
    }

    private function postCosts(string $type, array $ids, int $actor, string $day, ?\DateTimeInterface $at = null): void
    {
        foreach (DB::table('consignor_cost_entries')->where('source_type', $type)->whereIn('source_id', $ids)->get() as $e) {
            DB::table('consignor_cost_entries')->where('id', $e->id)->whereNull('event_on')->update(['event_on' => $day]);
            $amount = $e->value_delta === null ? null : abs((int) $e->value_delta);
            $terms = $type === 'pos_sale' ? [['cogs', 'finished', $amount]] : [['finished', 'cogs', $amount]];
            $reason = null;
            if ($type === 'sale_refund') {
                $original = DB::table('consignor_cost_entries')->where('id', $e->origin_id)->firstOrFail();
                $originalEvent = DB::table('accounting_events')->where('organization_id', $e->organization_id)->where('source_type', 'consignor_cogs')->where('source_id', $original->id)->first();
                if (($original->value_delta === null || (int) $original->value_delta !== 0) && (! $originalEvent || ! in_array($originalEvent->status, ['pending', 'posted'], true))) {
                    $reason = 'HPP konsinyasi asal belum sah/sudah direkonsiliasi manual; refund membutuhkan pemeriksaan sumber.';
                }
            } elseif ($at) {
                $activation = DB::table('consignor_pools as p')->join('consignor_valuations as v', 'v.id', '=', 'p.valuation_id')->where('p.id', $e->pool_id)->first(['v.created_at']);
                if ($at < CarbonImmutable::parse($activation->created_at, 'UTC')) {
                    $reason = 'Penjualan sebelum aktivasi valuasi konsinyasi; tidak mengimpor HPP cutover otomatis.';
                }
            }
            app(AutomaticPosting::class)->record($e->organization_id, $actor, $type === 'pos_sale' ? 'consignor_cogs' : 'consignor_refund_cogs', $e->id, $day, $terms, 'HPP fisik konsinyasi dari alokasi '.$e->source_id, $reason, $e->policy_id);
        }
    }

    public function received(SupplyOrder $order, string $type, string $line, int $quantity, int $actor): void
    {
        $lot = DB::table('consignor_lots')->where('source_type', $type)->where('source_id', $line)->first();
        if (! $lot) {
            return;
        }
        $this->require((int) $lot->received_quantity === $quantity && $quantity <= $lot->quantity, 'Receipt konsinyasi tidak sesuai ledger.');
        DB::table('consignor_lots')->where('id', $lot->id)->update(['receipt_confirmed' => true, 'updated_at' => now()]);
        if ($quantity < $lot->quantity && $lot->value !== null) {
            $r = DB::table('consignor_valuations')->where('id', $lot->valuation_id)->firstOrFail();
            $source = DB::table('inventory_cost_entries')->where('id', $lot->source_cost_entry_id)->firstOrFail();
            $shipment = $type === 'shipment_dispatch' ? DB::table('shipment_lines')->where('id', $line)->value('shipment_id') : DB::table('replacement_lines as l')->join('shipment_lines as o', 'o.id', '=', 'l.original_shipment_line_id')->where('l.id', $line)->value('o.shipment_id');
            $this->shortage($r, $order, $shipment, $source, $quantity, $actor, null, $lot->id);
        }
    }

    private function shortage(object $r, SupplyOrder $order, string $shipment, object $source, int $received, int $actor, ?string $private, string $reference): void
    {
        $sent = -(int) $source->quantity_delta;
        $cost = -(int) $source->value_delta;
        $missing = $cost - Proportion::floor($cost, $received, $sent);
        if ($missing === 0) {
            return;
        }
        app(AutomaticPosting::class)->record($r->organization_id, $actor, 'consignor_shortage', $reference, now('Asia/Jakarta')->format('Y-m-d'), [], 'Selisih fisik konsinyasi '.$reference.' · sisa Rp'.$missing, 'Tunggu pembagian beban hilang pusat dan pimpinan mitra.', $r->policy_id);
        $event = DB::table('accounting_events')->where('organization_id', $r->organization_id)->where('source_type', 'consignor_shortage')->where('source_id', $reference)->firstOrFail();
        DB::table('accounting_missing_costs')->insertOrIgnore(['id' => (string) Str::ulid(), 'order_id' => $order->id, 'shipment_id' => $shipment, 'owner_organization_id' => $r->organization_id, 'counterparty_organization_id' => $r->partner_id, 'source_entry_id' => $source->id, 'source_line_id' => $source->source_id, 'policy_id' => $r->policy_id, 'review_event_id' => $event->id, 'consignor_entry_id' => $private, 'sent_pieces' => $sent, 'received_pieces' => $received, 'missing_value' => $missing, 'event_on' => now('Asia/Jakarta')->format('Y-m-d'), 'status' => 'awaiting_agreement', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function missingSettled(object $r): void
    {
        if (! $r->review_event_id) {
            return;
        }
        if ($r->consignor_entry_id) {
            DB::table('consignor_returns')->where('source_entry_id', $r->consignor_entry_id)->update(['settled_missing_quantity' => $r->sent_pieces - $r->received_pieces, 'settled_missing_value' => $r->missing_value, 'updated_at' => now()]);
        } else {
            DB::table('consignor_lots')->where('source_cost_entry_id', $r->source_entry_id)->update(['settled_missing_quantity' => $r->sent_pieces - $r->received_pieces, 'settled_missing_value' => $r->missing_value, 'updated_at' => now()]);
        }
        DB::table('accounting_events')->where('organization_id', $r->owner_organization_id)->where('id', $r->review_event_id)->where('status', 'review_required')->update(['status' => 'reconciled', 'reason' => 'Beban hilang disetujui dua pihak '.$r->id, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }

    public function returnIncoming(InventoryBatch $batch, string $line, int $pieces): array
    {
        $ret = DB::table('consignor_returns')->where('return_line_id', $line)->lockForUpdate()->first();
        if (! $ret) {
            return ['handled' => false];
        }
        $r = DB::table('consignor_valuations')->where('id', $ret->valuation_id)->firstOrFail();
        abort_unless($batch->organization_id === $r->organization_id && $ret->status === 'in_transit' && $pieces > 0 && $pieces <= $ret->quantity, 409, 'Retur konsinyasi bukan milik gudang pemilik.');
        $cost = $ret->value === null ? null : Proportion::floor((int) $ret->value, $pieces, (int) $ret->quantity);
        DB::table('consignor_returns')->where('id', $ret->id)->update(['received_quantity' => $pieces, 'received_value' => $cost, 'status' => 'received', 'updated_at' => now()]);

        return ['handled' => true, 'value' => $cost];
    }

    public function returnReceived(SupplyOrder $order, object $line, int $actor): void
    {
        $ret = DB::table('consignor_returns')->where('return_line_id', $line->id)->first();
        if (! $ret) {
            return;
        }
        $r = DB::table('consignor_valuations')->where('id', $ret->valuation_id)->firstOrFail();
        $pieces = $line->received_packs * $line->pack_size;
        if ($pieces === 0) {
            DB::table('consignor_returns')->where('id', $ret->id)->update(['status' => 'received', 'updated_at' => now()]);
        }
        if ($ret->value !== null) {
            $receipt = app(DistributionCosting::class)->source($r->organization_id, 'return_receipt', $line->id);
            $active = $this->policy($r->organization_id);
            $original = $this->policy($r->organization_id, $r->policy_id);
            if ($pieces > 0 && $receipt && $active && $original && app(DistributionCosting::class)->matching($active, $original, ['finished']) && $receipt->value_delta !== null && (int) $receipt->value_delta === Proportion::floor((int) $ret->value, $pieces, (int) $ret->quantity)) {
                app(DistributionCosting::class)->cover($r->organization_id, 'return_receipt', $line->id, 'Retur aset konsinyasi ke pemilik; bukan pembelian atau pembalikan HPP barang terjual.');
            }
            if ($pieces < $ret->quantity) {
                $source = DB::table('consignor_cost_entries')->where('id', $ret->source_entry_id)->firstOrFail();
                $source->organization_id = $r->organization_id;
                $originalLine = DB::table('shipment_lines')->where('id', $line->original_shipment_line_id)->firstOrFail();
                $this->shortage($r, $order, $originalLine->shipment_id, $source, $pieces, $actor, $source->id, $ret->id);
            }
        }
    }

    private function disposition(object $r, string $entry, int $qty, ?int $cost, int $actor): void
    {
        app(AutomaticPosting::class)->record($r->organization_id, $actor, 'consignor_disposition', $entry, now('Asia/Jakarta')->format('Y-m-d'), [['loss', 'finished', $cost]], 'Susut fisik stok konsinyasi '.$entry, 'Pemilik wajib memeriksa susut; tidak dibebankan otomatis kepada mitra.', $r->policy_id);
        $event = DB::table('accounting_events')->where('organization_id', $r->organization_id)->where('source_type', 'consignor_disposition')->where('source_id', $entry)->firstOrFail();
        DB::table('consignor_dispositions')->insert(['id' => (string) Str::ulid(), 'organization_id' => $r->organization_id, 'entry_id' => $entry, 'event_id' => $event->id, 'quantity' => $qty, 'value' => $cost, 'status' => 'review_required', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function dispose(User $u, string $org, array $v): array
    {
        app(AccountingAccess::class)->manage($u, $org);
        $r = DB::table('consignor_dispositions')->where('organization_id', $org)->where('id', $v['id'])->lockForUpdate()->firstOrFail();
        $e = DB::table('accounting_events')->where('organization_id', $org)->where('id', $r->event_id)->lockForUpdate()->firstOrFail();
        abort_unless($r->status === 'review_required' && $r->version === $v['version'] && $r->value !== null && $e->status === 'review_required' && $e->actor_id !== $u->id, 409, 'Susut harus diketahui biayanya dan diperiksa pemilik yang berbeda dari pencatat.');
        app(DistributionCosting::class)->open($org, $e->event_on);
        DB::table('accounting_events')->where('id', $e->id)->update(['status' => (int) $r->value === 0 ? 'reconciled' : 'pending', 'reason' => (int) $r->value === 0 ? 'Susut biaya nol terbukti disetujui pemilik; tidak membuat jurnal nominal nol.' : null, 'version' => $e->version + 1, 'updated_at' => now()]);
        DB::table('consignor_dispositions')->where('id', $r->id)->update(['status' => 'approved', 'version' => $r->version + 1, 'reviewed_by' => $u->id, 'note' => $v['note'], 'updated_at' => now()]);
        Audit::record($u->id, $org, 'accounting.consignor_disposal_approved', $r->id, null, ['value' => $r->value, 'note' => $v['note']]);

        return ['id' => $r->id, 'organization_id' => $org, 'version' => $r->version + 1, 'status' => 'approved'];
    }
}
