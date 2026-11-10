<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Fulfillment\Infrastructure\ReplacementLine;
use App\Modules\Fulfillment\Infrastructure\ReturnLine;
use App\Modules\Identity\Application\Audit;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Shared\Domain\Proportion;
use App\Shared\Domain\Rupiah;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Prospective, source-proven distribution costing; operational acceptance never guesses a loss bearer. */
final class DistributionCosting
{
    public function blocked(string $org, string $batch): bool
    {
        return DB::table('accounting_distribution_jobs as j')->join('replacement_lines as r', 'r.id', '=', 'j.source_id')->join('inventory_cost_entries as e', function ($q) {
            $q->on('e.organization_id', '=', 'j.organization_id')->where(function ($q) {
                $q->where(fn ($q) => $q->where('e.source_type', 'replacement_receipt')->whereColumn('e.source_id', 'r.id'))->orWhere(fn ($q) => $q->where('e.source_type', 'shipment_receipt')->whereColumn('e.source_id', 'r.original_shipment_line_id'));
            });
        })->where('j.organization_id', $org)->where('j.kind', 'replacement')->where('j.status', '!=', 'applied')->where('e.inventory_batch_id', $batch)->exists();
    }

    public function require(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new DomainException($message);
        }
    }

    public function open(string $org, string $day): void
    {
        $this->require(DB::table('accounting_periods')->where('organization_id', $org)->where('status', 'open')->where('starts_on', '<=', $day)->where('ends_on', '>=', $day)->exists(), 'Periode sumber '.$day.' tertutup/tidak tersedia; periksa dan buka periode asal dahulu.');
    }

    public function policy(string $org, ?string $id = null): ?object
    {
        $q = DB::table('accounting_policies')->where('organization_id', $org)->whereNotNull('activated_at');

        return ($id ? $q->where('id', $id) : $q->where('status', 'active'))->first();
    }

    public function matching(object $a, object $b, array $roles): bool
    {
        $left = json_decode($a->mapping, true);
        $right = json_decode($b->mapping, true);
        foreach ($roles as $role) {
            if (! isset($left[$role], $right[$role]) || $left[$role]['account_id'] !== $right[$role]['account_id']) {
                return false;
            }
        }

        return true;
    }

    public function source(string $org, string $type, string $id): ?object
    {
        return DB::table('inventory_cost_entries')->where('organization_id', $org)->where('source_type', $type)->where('source_id', $id)->first();
    }

    public function review(object $entry): ?object
    {
        return DB::table('accounting_events')->where('organization_id', $entry->organization_id)->where('source_type', 'stock_review')->where('source_id', $entry->id)->lockForUpdate()->first();
    }

    public function cover(string $org, string $type, string $line, string $note): void
    {
        DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'stock_review')->whereIn('source_id', DB::table('inventory_cost_entries')->where('organization_id', $org)->where('source_type', $type)->where('source_id', $line)->select('id'))->where('status', 'review_required')->update(['status' => 'reconciled', 'reason' => $note, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }

    public function missing(SupplyOrder $order, string $shipment, object $cost, int $received, int $actor): void
    {
        $sent = -(int) $cost->quantity_delta;
        if ($received >= $sent || $cost->value_delta === null || $cost->value_delta > 0) {
            return;
        }
        $event = $this->review($cost);
        if (! $event || $event->status !== 'review_required') {
            return;
        }
        $value = -(int) $cost->value_delta;
        $residual = $value - Proportion::floor($value, $received, $sent);
        if ($residual === 0) {
            return;
        }
        DB::table('accounting_missing_costs')->insertOrIgnore(['id' => (string) Str::ulid(), 'order_id' => $order->id, 'shipment_id' => $shipment, 'owner_organization_id' => $cost->organization_id, 'counterparty_organization_id' => $cost->organization_id === $order->organization_id ? $order->supplier_organization_id : $order->organization_id, 'source_entry_id' => $cost->id, 'source_line_id' => $cost->source_id, 'policy_id' => $event->policy_id, 'sent_pieces' => $sent, 'received_pieces' => $received, 'missing_value' => $residual, 'event_on' => now('Asia/Jakarta')->format('Y-m-d'), 'status' => 'awaiting_agreement', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function maybeCover(object $entry): void
    {
        $missing = DB::table('accounting_missing_costs')->where('source_entry_id', $entry->id)->first();
        if ($missing && $missing->status !== 'approved') {
            return;
        }
        if ($entry->source_type === 'return_dispatch') {
            $r = DB::table('accounting_return_costs')->where('return_line_id', $entry->source_id)->first();
            if (! $r || ($r->received_pieces > 0 && $r->status !== 'released')) {
                return;
            }
        }
        $this->cover($entry->organization_id, $entry->source_type, $entry->source_id, 'Biaya penerimaan dan sisa hilang telah dicakup bukti distribusi serta persetujuan dua pihak.');
    }

    private function job(string $org, SupplyOrder $order, string $kind, string $line, string $policy, int $actor): string
    {
        DB::table('accounting_distribution_jobs')->insertOrIgnore(['id' => (string) Str::ulid(), 'organization_id' => $org, 'order_id' => $order->id, 'kind' => $kind, 'source_id' => $line, 'policy_id' => $policy, 'actor_id' => $actor, 'event_on' => now('Asia/Jakarta')->format('Y-m-d'), 'status' => 'pending', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('accounting_distribution_jobs')->where('organization_id', $org)->where('kind', $kind)->where('source_id', $line)->value('id');
    }

    public function replacementPolicy(SupplyOrder $order, ReplacementLine $line): ?object
    {
        $e = app(ReceiptCostEvidence::class)->read($order->organization_id, [$line->original_shipment_line_id])['origins'][$line->original_shipment_line_id] ?? null;

        return $e && $e->trusted ? $this->policy($order->organization_id, $e->policy_id) : null;
    }

    public function replacementReceived(SupplyOrder $order, ReplacementLine $line, int $actor): void
    {
        $cost = $this->source($order->supplier_organization_id, 'replacement_dispatch', $line->id);
        $origin = $cost ? $this->review($cost) : null;
        $p = $this->policy($order->supplier_organization_id);
        $old = $origin ? $this->policy($order->supplier_organization_id, $origin->policy_id) : null;
        $buyer = $this->replacementPolicy($order, $line);
        if ($buyer && $cost && $p && $old && $cost->value_delta !== null && $cost->value_delta <= 0 && $origin->status === 'review_required' && $this->matching($old, $p, ['finished', 'loss'])) {
            $value = -(int) $cost->value_delta;
            $received = ($line->good_packs + $line->damaged_packs) * $line->pack_size;
            $amount = Proportion::floor($value, $received, -(int) $cost->quantity_delta);
            app(AutomaticPosting::class)->record($order->supplier_organization_id, $actor, 'replacement_cost', $line->id, now('Asia/Jakarta')->format('Y-m-d'), [['loss', 'finished', $amount]], 'Beban pengganti disepakati; penerimaan aktual '.$line->id, null, $p->id);
            $originalShipment = DB::table('shipment_lines')->where('id', $line->original_shipment_line_id)->value('shipment_id');
            $this->missing($order, $originalShipment, $cost, $received, $actor);
            $this->maybeCover($cost);
        }
        if ($buyer) {
            $this->cover($order->organization_id, 'replacement_receipt', $line->id, 'Pengganti tanpa tagihan; nilai pembelian tersisa dibagi pada barang milik pembeli.');
            $id = $this->job($order->organization_id, $order, 'replacement', $line->id, $buyer->id, $actor);
            $this->apply($id);
        }
    }

    public function returnDispatched(SupplyOrder $order, object $case, ReturnLine $line, int $actor): void
    {
        $buyer = app(ReceiptCostEvidence::class)->read($order->organization_id, [$line->original_shipment_line_id])['origins'][$line->original_shipment_line_id] ?? null;
        $cost = $this->source($order->organization_id, 'return_dispatch', $line->id);
        $source = $this->source($order->supplier_organization_id, 'shipment_dispatch', $line->original_shipment_line_id);
        $cogs = DB::table('accounting_events')->where('organization_id', $order->supplier_organization_id)->where('source_type', 'distribution_cogs')->where('source_id', $line->original_shipment_line_id)->first();
        $invoice = DB::table('invoice_shipment_lines as l')->join('invoices as i', 'i.id', '=', 'l.invoice_id')->where('l.shipment_line_id', $line->original_shipment_line_id)->where('i.status', 'accepted')->where('i.mode', 'buyout')->first(['i.id']);
        $final = $invoice ? DB::table('accounting_invoice_revaluations')->where('organization_id', $order->organization_id)->where('invoice_id', $invoice->id)->where('status', 'applied')->exists() : false;
        $sourceEvent = $source ? $this->review($source) : null;
        $sellerPolicy = $cogs?->policy_id ?? $sourceEvent?->policy_id;
        if (! $buyer || ! $buyer->trusted || ! $cost || $cost->value_delta === null || ! $source || $source->value_delta === null || $source->value_delta > 0 || ! $sellerPolicy || ! $final || ((int) $source->value_delta !== 0 && (! $cogs || ! in_array($cogs->status, ['pending', 'posted'], true)))) {
            return;
        }
        $prior = (int) DB::table('return_lines')->where('original_shipment_line_id', $line->original_shipment_line_id)->where('id', '<', $line->id)->sum('quantity_packs') * $line->pack_size;
        $pieces = $line->quantity_packs * $line->pack_size;
        $sourceValue = -(int) $source->value_delta;
        $sent = -(int) $source->quantity_delta;
        $recovery = Proportion::floor($sourceValue, $prior + $pieces, $sent) - Proportion::floor($sourceValue, $prior, $sent);
        DB::table('accounting_return_costs')->insert(['id' => (string) Str::ulid(), 'return_line_id' => $line->id, 'order_id' => $order->id, 'case_id' => $case->id, 'invoice_id' => $invoice->id, 'original_shipment_line_id' => $line->original_shipment_line_id, 'buyer_organization_id' => $order->organization_id, 'supplier_organization_id' => $order->supplier_organization_id, 'buyer_policy_id' => $buyer->policy_id, 'supplier_policy_id' => $sellerPolicy, 'dispatch_cost_entry_id' => $cost->id, 'sent_pieces' => $pieces, 'buyer_value' => -(int) $cost->value_delta, 'supplier_value' => $recovery, 'status' => 'in_transit', 'created_at' => now(), 'updated_at' => now()]);
        $this->job($order->organization_id, $order, 'return', $line->id, $buyer->policy_id, $actor);
    }

    public function custody(InventoryBatch $batch, string $line, int $pieces): bool
    {
        $r = DB::table('accounting_return_costs')->where('return_line_id', $line)->lockForUpdate()->first();
        if (! $r) {
            return false;
        }
        abort_unless($r->supplier_organization_id === $batch->organization_id && $r->status === 'in_transit' && $pieces > 0 && $pieces <= $r->sent_pieces, 409, 'Bukti titipan retur tidak cocok.');
        DB::table('accounting_return_costs')->where('id', $r->id)->update(['inventory_batch_id' => $batch->id, 'received_pieces' => $pieces, 'received_on' => now('Asia/Jakarta')->format('Y-m-d'), 'status' => 'held', 'updated_at' => now()]);

        return true;
    }

    public function returnReceived(SupplyOrder $order, ReturnLine $line, int $actor): void
    {
        $r = DB::table('accounting_return_costs')->where('return_line_id', $line->id)->first();
        if (! $r) {
            return;
        }
        $cost = $this->source($order->organization_id, 'return_dispatch', $line->id);
        $shipment = DB::table('shipment_lines')->where('id', $line->original_shipment_line_id)->value('shipment_id');
        $this->missing($order, $shipment, $cost, $line->received_packs * $line->pack_size, $actor);
        if ($line->received_packs === 0) {
            DB::table('accounting_return_costs')->where('id', $r->id)->update(['status' => 'lost', 'received_on' => now('Asia/Jakarta')->format('Y-m-d'), 'updated_at' => now()]);
            $this->maybeCover($cost);
            $job = DB::table('accounting_distribution_jobs')->where('organization_id', $order->organization_id)->where('kind', 'return')->where('source_id', $line->id)->first();
            if ($job) {
                $this->apply($job->id);
            }

            return;
        }
        foreach ([[$order->organization_id, $r->buyer_policy_id], [$order->supplier_organization_id, $r->supplier_policy_id]] as [$org, $policy]) {
            $this->job($org, $order, 'return', $line->id, $policy, $actor);
        }
        $id = DB::table('accounting_distribution_jobs')->where('organization_id', $order->organization_id)->where('kind', 'return')->where('source_id', $line->id)->value('id');
        $this->apply($id);
    }

    public function wake(string $order, string $prefix): void
    {
        DB::table('accounting_distribution_jobs')->where('order_id', $order)->where('status', 'review_required')->where('reason', 'like', $prefix.'%')->update(['status' => 'pending', 'reason' => null, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }

    public function retry(string $org, array $v): array
    {
        $r = (array) DB::table('accounting_distribution_jobs')->where('organization_id', $org)->where('id', $v['id'])->lockForUpdate()->firstOrFail();
        abort_unless($r['status'] === 'review_required' && $r['version'] === $v['version'], 409);
        $new = ['status' => 'pending', 'reason' => null, 'version' => $r['version'] + 1, 'updated_at' => now()];
        DB::table('accounting_distribution_jobs')->where('id', $r['id'])->update($new);

        return [...$r, ...$new];
    }

    public function drain(int $limit = 100): int
    {
        $done = 0;
        foreach (DB::table('accounting_distribution_jobs')->where('status', 'pending')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            if ($this->apply($id)) {
                $done++;
            }
        }

        return $done;
    }

    public function apply(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $seed = DB::table('accounting_distribution_jobs')->where('id', $id)->firstOrFail();
            $order = SupplyOrder::whereKey($seed->order_id)->lockForUpdate()->firstOrFail();
            $job = DB::table('accounting_distribution_jobs')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($job->status === 'applied') {
                return false;
            }
            try {
                // Savepoint prevents any earlier batch/GL mutation surviving a later failed prerequisite.
                DB::transaction(function () use ($job, $order) {
                    if ($job->kind === 'replacement') {
                        $this->replace($job, $order);
                    } else {
                        $this->releaseReturn($job, $order);
                    }
                });

                return true;
            } catch (DomainException $e) {
                Audit::record($job->actor_id, $job->organization_id, 'accounting.distribution_valuation_review', $id, null, ['automatic' => true, 'kind' => $job->kind, 'source_id' => $job->source_id, 'reason' => $e->getMessage(), 'version' => $job->version + 1]);
                DB::table('accounting_distribution_jobs')->where('id', $id)->update(['status' => 'review_required', 'reason' => $e->getMessage(), 'version' => $job->version + 1, 'updated_at' => now()]);

                return false;
            }
        }, 3);
    }

    private function done(object $job, array $result, ?string $day = null): void
    {
        Audit::record($job->actor_id, $job->organization_id, 'accounting.distribution_valued', $job->id, null, ['automatic' => true, 'kind' => $job->kind, 'source_id' => $job->source_id, 'result' => $result]);
        DB::table('accounting_distribution_jobs')->where('id', $job->id)->update(['status' => 'applied', 'reason' => null, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'event_on' => $day ?? $job->event_on, 'version' => $job->version + 1, 'updated_at' => now()]);
    }

    private function value(object $job, InventoryBatch $batch, int $afterQuantity, int $after): void
    {
        $pool = DB::table('inventory_values')->where('inventory_batch_id', $batch->id)->lockForUpdate()->firstOrFail();
        $this->require($pool->remaining_value !== null, 'Nilai batch belum diketahui; bukan biaya nol.');
        $id = (string) Str::ulid();
        $delta = $after - (int) $pool->remaining_value;
        DB::table('inventory_distribution_values')->insert(['id' => $id, 'organization_id' => $job->organization_id, 'job_id' => $job->id, 'inventory_batch_id' => $batch->id, 'stock_revision' => $batch->stock_revision, 'before_quantity' => $pool->quantity, 'after_quantity' => $afterQuantity, 'before_value' => $pool->remaining_value, 'after_value' => $after, 'value_delta' => $delta, 'created_at' => now()]);
        app(StockLedger::class)->apply($batch, $job->actor_id, [], 'distribution_value', $id, 'Biaya/kepemilikan distribusi '.$job->id, valueDelta: $delta);
    }

    private function replace(object $job, SupplyOrder $order): void
    {
        $line = ReplacementLine::findOrFail($job->source_id);
        $original = $line->original_shipment_line_id;
        $invoice = DB::table('invoice_shipment_lines')->where('shipment_line_id', $original)->value('invoice_id');
        $this->require($invoice && DB::table('accounting_invoice_revaluations')->where('organization_id', $job->organization_id)->where('invoice_id', $invoice)->where('status', 'applied')->exists(), 'Invoice final belum direkonsiliasi; tunggu biaya pembelian asal sebelum pembagian pengganti.');
        $this->open($job->organization_id, $job->event_on);
        $replacementIds = DB::table('replacement_lines')->where('original_shipment_line_id', $original)->pluck('id')->all();
        $receipts = DB::table('inventory_cost_entries')->where('organization_id', $job->organization_id)->where(fn ($q) => $q->where(fn ($q) => $q->where('source_type', 'shipment_receipt')->where('source_id', $original))->orWhere(fn ($q) => $q->where('source_type', 'replacement_receipt')->whereIn('source_id', $replacementIds)))->limit(10001)->get();
        $this->require($receipts->count() <= 10000 && strlen(json_encode($receipts)) <= 8 * 1024 * 1024, 'Bukti pengganti melampaui batas.');
        $batches = InventoryBatch::where('organization_id', $job->organization_id)->whereIn('id', $receipts->pluck('inventory_batch_id')->unique())->orderBy('id')->lockForUpdate()->get();
        $this->require($batches->count() <= 100 && ! $receipts->contains(fn ($r) => $r->value_delta === null), 'Penerimaan pengganti tidak mempunyai biaya asal yang terbukti.');
        $qty = $total = 0;
        $pools = [];
        $bytes = 0;
        $entryCount = 0;
        foreach ($batches as $batch) {
            $entries = DB::table('inventory_cost_entries')->where('inventory_batch_id', $batch->id)->limit(10001)->get();
            $bytes += strlen(json_encode($entries));
            $entryCount += $entries->count();
            $this->require($entryCount <= 10000 && $bytes <= 8 * 1024 * 1024, 'Bukti pembagian pengganti melampaui batas.');
            foreach ($entries as $e) {
                $allowed = in_array($e->source_type, ['pos_sale', 'sale_refund', 'invoice_variance', 'distribution_value'], true) || ($e->source_type === 'shipment_receipt' && $e->source_id === $original) || ($e->source_type === 'replacement_receipt' && in_array($e->source_id, $replacementIds, true));
                if (! $allowed && (int) $e->quantity_delta < 0 && $e->value_delta !== null) {
                    $loss = DB::table('accounting_events')->where('organization_id', $job->organization_id)->where('source_type', 'stock_loss')->where('source_id', $e->id)->first();
                    $allowed = $loss && in_array($loss->status, ['pending', 'posted'], true);
                }
                if (! $allowed && (int) $e->quantity_delta === 0 && (int) $e->value_delta === 0) {
                    $allowed = true;
                }
                $this->require($allowed, 'Pool pengganti bercampur dengan sumber/order/transfer lain; perlu alokasi lineage terperinci.');
            }
            $pool = DB::table('inventory_values')->where('inventory_batch_id', $batch->id)->lockForUpdate()->first();
            $this->require($pool && $pool->remaining_value !== null && (int) $pool->quantity === app(InventoryValuation::class)->quantity($batch), 'Nilai/jumlah stok pengganti tidak cocok.');
            $qty += (int) $pool->quantity;
            $total = Rupiah::add($total, (int) $pool->remaining_value);
            $pools[$batch->id] = $pool;
        }
        $weight = $allocated = 0;
        foreach ($batches as $batch) {
            $weight += (int) $pools[$batch->id]->quantity;
            $next = $qty > 0 ? Proportion::floor($total, $weight, $qty) : 0;
            $this->value($job, $batch, (int) $pools[$batch->id]->quantity, $next - $allocated);
            $allocated = $next;
        }
        $this->require($allocated === $total, 'Pembagian pengganti tidak konservatif.');
        $this->done($job, ['remaining_pieces' => $qty, 'conserved_value' => $total, 'original_shipment_line_id' => $original, 'historical_cogs_unchanged' => true]);
    }

    private function releaseReturn(object $job, SupplyOrder $order): void
    {
        $seed = DB::table('accounting_return_costs')->where('return_line_id', $job->source_id)->firstOrFail();
        $this->require($seed->status !== 'in_transit', 'Menunggu penerimaan fisik retur; barang masih milik pembeli dalam perjalanan.');
        if ($seed->status === 'lost') {
            $missing = DB::table('accounting_missing_costs')->where('source_entry_id', $seed->dispatch_cost_entry_id)->first();
            $this->require(! $missing || $missing->status === 'approved', 'Barang retur tidak diterima; tunggu kesepakatan biaya hilang.');
            $this->done($job, ['return_line_id' => $seed->return_line_id, 'returned_value' => 0, 'ownership' => 'lost_settled_separately']);

            return;
        }
        if ($seed->status === 'released') {
            $this->done($job, ['return_line_id' => $seed->return_line_id, 'ownership' => 'supplier']);

            return;
        }
        $rows = DB::table('accounting_return_costs')->where('case_id', $seed->case_id)->where('invoice_id', $seed->invoice_id)->whereIn('status', ['held', 'released'])->orderBy('id')->limit(101)->get();
        $credits = DB::table('invoice_credits')->where('case_id', $seed->case_id)->where('invoice_id', $seed->invoice_id)->where('status', 'accepted')->orderBy('id')->limit(101)->get();
        $invoice = DB::table('invoices')->where('id', $seed->invoice_id)->firstOrFail();
        $invoiceLines = collect(json_decode($invoice->lines, true));
        $this->require($rows->count() <= 100 && $credits->count() <= 100 && strlen(json_encode([$rows, $credits, $invoice])) <= 8 * 1024 * 1024, 'Bukti retur/kredit melampaui batas.');
        $gross = 0;
        $creditWeights = [];
        foreach ($rows as $row) {
            $il = $invoiceLines->firstWhere('shipment_line_id', $row->original_shipment_line_id);
            $this->require((bool) $il, 'Baris invoice retur tidak ditemukan.');
            $creditWeights[$row->id] = Proportion::floor((int) $il['unit_price'], (int) $row->received_pieces, (int) DB::table('return_lines')->where('id', $row->return_line_id)->value('pack_size'));
            $gross = Rupiah::add($gross, $creditWeights[$row->id]);
        }
        $required = Proportion::floor((int) $invoice->subtotal - (int) $invoice->discount_amount, $gross, (int) $invoice->subtotal);
        $this->require($credits->isNotEmpty() && (int) $credits->sum('base_amount') >= $required, 'Menunggu nota kredit disetujui yang mencakup barang retur; kepemilikan masih pembeli.');
        $pending = $rows->where('status', 'held');
        $base = (int) $credits->sum('base_amount') - (int) $rows->sum('credit_base');
        $tax = (int) $credits->sum('tax_amount') - (int) $rows->sum('credit_tax');
        $weight = (int) $pending->sum(fn ($r) => $creditWeights[$r->id]);
        if ($weight === 0) {
            foreach ($pending as $r) {
                $creditWeights[$r->id] = (int) $r->received_pieces;
            } $weight = (int) $pending->sum('received_pieces');
        }
        $cum = $assignedBase = $assignedTax = 0;
        $batches = InventoryBatch::where('organization_id', $order->supplier_organization_id)->whereIn('id', $pending->pluck('inventory_batch_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $this->require($base >= 0 && $tax >= 0 && $weight > 0, 'Alokasi kredit retur tidak konservatif.');
        foreach ($pending as $r) {
            // Credit timestamps are UTC; use the same WIB calendar as financial capture.
            $day = max($r->received_on, CarbonImmutable::parse($credits->last()->decided_at, 'UTC')->setTimezone('Asia/Jakarta')->format('Y-m-d'));
            foreach ([$order->organization_id, $order->supplier_organization_id] as $org) {
                $this->open($org, $day);
            }
            $source = $this->source($order->organization_id, 'return_dispatch', $r->return_line_id);
            $review = $source ? $this->review($source) : null;
            $this->require($review && $review->status === 'review_required', 'Dispatch retur sudah direkonsiliasi manual atau tanpa bukti sumber.');
            $buyerPolicy = $this->policy($order->organization_id, $r->buyer_policy_id);
            $sellerPolicy = $this->policy($order->supplier_organization_id, $r->supplier_policy_id);
            $this->require($buyerPolicy && $sellerPolicy, 'Pemetaan retur asal tidak tersedia.');
            foreach ($credits as $credit) {
                foreach ([[$order->organization_id, $buyerPolicy], [$order->supplier_organization_id, $sellerPolicy]] as [$org, $p]) {
                    $e = DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'wholesale_credit')->where('source_id', $credit->id)->first();
                    $cp = $e ? $this->policy($org, $e->policy_id) : null;
                    $this->require($e && in_array($e->status, ['pending', 'posted'], true) && $cp && $this->matching($p, $cp, ['finished', 'purchase_clearing', 'cogs', 'loss']), 'Jurnal/pemetaan nota kredit perlu rekonsiliasi.');
                }
            }
            $cum += $creditWeights[$r->id];
            $nextBase = Proportion::floor($base, $cum, $weight);
            $nextTax = Proportion::floor($tax, $cum, $weight);
            $allocatedBase = $nextBase - $assignedBase;
            $allocatedTax = $nextTax - $assignedTax;
            $assignedBase = $nextBase;
            $assignedTax = $nextTax;
            $buyerValue = Proportion::floor((int) $r->buyer_value, (int) $r->received_pieces, (int) $r->sent_pieces);
            $sellerValue = Proportion::floor((int) $r->supplier_value, (int) $r->received_pieces, (int) $r->sent_pieces);
            $buyerSettings = DB::table('accounting_wholesale_policies')->where('policy_id', $r->buyer_policy_id)->firstOrFail();
            $creditCapital = $allocatedBase + ($buyerSettings->tax_treatment === 'clearing' ? $allocatedTax : 0);
            $difference = $buyerValue - $creditCapital;
            $buyerTerms = [['purchase_clearing', 'finished', $buyerValue]];
            if ($difference > 0) {
                $buyerTerms[] = ['loss', 'purchase_clearing', $difference];
            } elseif ($difference < 0) {
                $buyerTerms[] = ['purchase_clearing', 'loss', -$difference];
            }
            $buyerJob = DB::table('accounting_distribution_jobs')->where('organization_id', $order->organization_id)->where('kind', 'return')->where('source_id', $r->return_line_id)->firstOrFail();
            $sellerJob = DB::table('accounting_distribution_jobs')->where('organization_id', $order->supplier_organization_id)->where('kind', 'return')->where('source_id', $r->return_line_id)->firstOrFail();
            $batch = $batches[$r->inventory_batch_id];
            $pool = DB::table('inventory_values')->where('inventory_batch_id', $batch->id)->firstOrFail();
            DB::table('accounting_return_costs')->where('id', $r->id)->update(['status' => 'released', 'credit_base' => $allocatedBase, 'credit_tax' => $allocatedTax, 'updated_at' => now()]);
            // Only now do custody pieces become supplier-owned inventory quantity/value.
            $this->value($sellerJob, $batch, (int) $pool->quantity + (int) $r->received_pieces, Rupiah::add((int) $pool->remaining_value, $sellerValue));
            $batches[$batch->id] = $batch->fresh();
            app(AutomaticPosting::class)->record($order->organization_id, $job->actor_id, 'distribution_return_value', $r->return_line_id, $day, $buyerTerms, 'Retur diterima dan kredit disetujui; kepemilikan ke pemasok '.$r->return_line_id, null, $r->buyer_policy_id);
            app(AutomaticPosting::class)->record($order->supplier_organization_id, $job->actor_id, 'distribution_return_value', $r->return_line_id, $day, [['finished', 'cogs', $sellerValue]], 'Pemulihan biaya batch asal, bukan harga jual '.$r->return_line_id, null, $r->supplier_policy_id);
            $this->done($buyerJob, ['return_line_id' => $r->return_line_id, 'returned_value' => $buyerValue, 'credit_capital' => $creditCapital, 'loss_adjustment' => $difference, 'ownership' => 'supplier'], $day);
            $this->done($sellerJob, ['return_line_id' => $r->return_line_id, 'recovered_value' => $sellerValue, 'ownership' => 'supplier'], $day);
            $this->maybeCover($source);
        }
    }
}
