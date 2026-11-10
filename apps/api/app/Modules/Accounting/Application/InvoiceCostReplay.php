<?php

namespace App\Modules\Accounting\Application;

use App\Shared\Domain\Proportion;
use App\Shared\Domain\Rupiah;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Replays all proven buyout receipts and correction layers in one conserved batch pool. */
final class InvoiceCostReplay
{
    private function require(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new DomainException($message);
        }
    }

    private function day(mixed $d): string
    {
        return CarbonImmutable::parse($d, 'UTC')->setTimezone('Asia/Jakarta')->format('Y-m-d');
    }

    public function plan(string $org, string $batch, Collection $entries, array $prices, array $targets, array $variances, array $mapping, callable $checkDay): array
    {
        $allocationIds = $entries->where('source_type', 'pos_sale')->pluck('source_id')->unique()->all();
        $sales = DB::table('sale_allocations as a')->join('sale_lines as l', 'l.id', '=', 'a.sale_line_id')->join('sales as s', 's.id', '=', 'l.sale_id')->where('s.organization_id', $org)->where('a.inventory_batch_id', $batch)->whereIn('a.id', $allocationIds)->select('a.id as allocation_id', 'a.pieces', 's.id', 's.sold_at', 's.total_amount', 's.discount_amount')->get()->keyBy('allocation_id');
        $refunds = DB::table('sale_refunds')->whereIn('sale_id', $sales->pluck('id')->unique()->all())->orderBy('id')->limit(10001)->get();
        $this->require($refunds->count() <= 10000 && strlen(json_encode($refunds)) <= 8 * 1024 * 1024, 'Bukti refund melampaui10.000/8MiB; perlu rekonsiliasi terperinci.');
        $refundProof = [];
        foreach ($refunds as $r) {
            foreach (json_decode($r->lines, true, 512, JSON_THROW_ON_ERROR) as $line) {
                foreach ($line['allocations'] as $a) {
                    if ($a['batch_id'] === $batch) {
                        $this->require(! isset($refundProof[$a['source_id']]), 'Bukti refund ganda.');
                        $refundProof[$a['source_id']] = ['refund' => $r, 'allocation' => $a];
                    }
                }
            }
        }
        $events = DB::table('accounting_events')->where('organization_id', $org)->where(fn ($q) => $q->where(fn ($q) => $q->where('source_type', 'pos_sale')->whereIn('source_id', $sales->pluck('id')->all()))->orWhere(fn ($q) => $q->where('source_type', 'pos_refund')->whereIn('source_id', $refunds->pluck('id')->all()))->orWhere(fn ($q) => $q->where('source_type', 'stock_loss')->whereIn('source_id', $entries->pluck('id')->all())))->limit(10001)->get();
        $wasteRows = DB::table('stock_entries')->where('organization_id', $org)->where('inventory_batch_id', $batch)->where('bucket', 'waste')->whereIn('source_id', $entries->pluck('source_id')->unique()->all())->select('source_type', 'source_id')->selectRaw('SUM(quantity_pieces) AS pieces')->groupBy('source_type', 'source_id')->limit(10001)->get();
        $this->require($wasteRows->count() <= 10000, 'Bukti susut melampaui10.000sumber.');
        $wasteProof = $wasteRows->keyBy(fn ($r) => $r->source_type.':'.$r->source_id);
        $policyRows = DB::table('accounting_policies')->where('organization_id', $org)->whereIn('id', $events->pluck('policy_id')->unique()->all())->limit(1001)->get(['id', 'mapping']);
        $this->require($events->count() <= 10000 && $policyRows->count() <= 1000 && strlen(json_encode([$sales, $refunds, $events, $policyRows, $wasteRows])) <= 8 * 1024 * 1024, 'Bukti jurnal/retur/pemetaan melampaui batas; perlu rekonsiliasi terperinci.');
        $policies = $policyRows->mapWithKeys(fn ($r) => [$r->id => json_decode($r->mapping, true)])->all();
        $events = $events->keyBy(fn ($r) => $r->source_type.':'.$r->source_id);
        $checkEvent = function (?object $e, string $role, string $day) use ($mapping, $policies, $checkDay): void {
            $this->require($e && in_array($e->status, ['pending', 'posted'], true) && $e->event_on === $day, 'Jurnal sumber pergerakan belum tersedia atau perlu rekonsiliasi.');
            $m = $policies[$e->policy_id] ?? [];
            foreach ([$role, 'finished'] as $key) {
                $this->require(isset($m[$key],$mapping[$key]) && $m[$key]['account_id'] === $mapping[$key]['account_id'], 'Akun sumber biaya berbeda; perlu rekonsiliasi pemetaan.');
            }
            $checkDay($day);
        };
        $saleLayers = DB::table('inventory_invoice_sale_adjustments')->where('organization_id', $org)->whereIn('variance_id', array_keys($variances))->whereIn('cost_entry_id', $entries->pluck('id')->all())->limit(10001)->get();
        $movementLayers = DB::table('inventory_invoice_movement_adjustments')->where('organization_id', $org)->whereIn('variance_id', array_keys($variances))->whereIn('cost_entry_id', $entries->pluck('id')->all())->limit(10001)->get();
        $this->require($saleLayers->count() <= 10000 && $movementLayers->count() <= 10000, 'Lapisan biaya sebelumnya melampaui batas.');
        $saleDeltas = $saleLayers->groupBy('cost_entry_id')->map(fn ($rows) => (int) $rows->sum('cost_delta'));
        $movementDeltas = $movementLayers->groupBy('cost_entry_id')->map(fn ($rows) => (int) $rows->sum('cost_delta'));
        $evidenceBytes = strlen(json_encode([$sales, $refunds, $events, $policyRows, $wasteRows, $saleLayers, $movementLayers]));
        $this->require($evidenceBytes <= 8 * 1024 * 1024, 'Bukti koreksi invoice melampaui8MiB.');
        $qty = $oldValue = $newValue = 0;
        $layers = [];
        $movements = [];
        $saleCosts = [];
        $refundRanges = [];
        $cogs = $loss = 0;
        foreach ($entries->sortBy([['stock_revision', 'asc'], ['id', 'asc']]) as $e) {
            if ($e->source_type === 'shipment_receipt' && (int) $e->quantity_delta > 0) {
                $this->require(array_key_exists($e->id, $prices), 'Penerimaan tidak mempunyai harga asal yang dapat dibuktikan.');
                $qty += (int) $e->quantity_delta;
                $oldValue = Rupiah::add($oldValue, $prices[$e->id]);
                $newValue = Rupiah::add($newValue, $targets[$e->id] ?? $prices[$e->id]);

                continue;
            }
            if ($e->source_type === 'replacement_receipt' && (int) $e->quantity_delta > 0 && $e->value_delta !== null && (int) $e->value_delta === 0) {
                $original = DB::table('replacement_lines')->where('id', $e->source_id)->value('original_shipment_line_id');
                $proven = $entries->where('source_type', 'shipment_receipt')->where('source_id', $original)->isNotEmpty();
                $job = DB::table('accounting_distribution_jobs')->where('organization_id', $org)->where('kind', 'replacement')->where('source_id', $e->source_id)->first();
                $this->require($proven && $job && $job->status !== 'applied', 'Pengganti sudah dibagi biaya atau tidak mempunyai asal; perlu rekonsiliasi invoice.');
                $qty += (int) $e->quantity_delta;

                continue;
            }
            if ((int) $e->quantity_delta === 0) {
                if ($e->source_type === 'invoice_variance') {
                    $v = $variances[$e->source_id] ?? null;
                    $this->require($v && $e->value_delta !== null && (int) $v->stock_adjustment !== 0 && (int) $v->stock_adjustment === (int) $e->value_delta && (int) $e->stock_revision === (int) $v->stock_revision + 1, 'Koreksi nilai terdahulu tidak mempunyai bukti immutable.');
                } else {
                    $this->require($e->value_delta !== null && (int) $e->value_delta === 0, 'Koreksi nilai lain memerlukan rekonsiliasi.');
                }

                continue;
            }
            $this->require($e->value_delta !== null, 'Biaya tidak diketahui; perlu rekonsiliasi.');
            $n = abs((int) $e->quantity_delta);
            if ($e->source_type === 'sale_refund' && (int) $e->quantity_delta > 0) {
                $proof = $refundProof[$e->source_id] ?? null;
                $original = $saleCosts[$e->origin_id] ?? null;
                $a = $proof['allocation'] ?? [];
                $this->require($proof && $original && ($a['sale_allocation_id'] ?? '') === $e->origin_id && (int) ($a['pieces'] ?? 0) === $n && $proof['refund']->sale_id === $original['sale_id'] && isset($a['cost_offset']), 'Retur tidak mempunyai bukti alokasi/offset asal.');
                $start = (int) $a['cost_offset'];
                $end = $start + $n;
                $this->require($start >= 0 && $end <= $original['qty'], 'Offset retur melebihi alokasi asal.');
                foreach ($refundRanges[$e->origin_id] ?? [] as [$left,$right]) {
                    $this->require($end <= $left || $start >= $right, 'Offset retur bertumpang tindih.');
                }
                $refundRanges[$e->origin_id][] = [$start, $end];
                $old = Proportion::floor($original['old'], $end, $original['qty']) - Proportion::floor($original['old'], $start, $original['qty']);
                $new = Proportion::floor($original['new'], $end, $original['qty']) - Proportion::floor($original['new'], $start, $original['qty']);
                $this->require((int) $e->value_delta - ($movementDeltas->get($e->id) ?? 0) === $old, 'Biaya retur asal tidak sesuai bukti.');
                $day = $this->day($proof['refund']->refunded_at);
                $checkEvent($events->get('pos_refund:'.$proof['refund']->id), 'cogs', $day);
                $delta = -($new - $old);
                $cogs += $delta;
                $qty += $n;
                $oldValue = Rupiah::add($oldValue, $old);
                $newValue = Rupiah::add($newValue, $new);
                $movements[] = ['id' => $e->id, 'delta' => $delta, 'role' => 'cogs', 'day' => $day];

                continue;
            }
            $this->require((int) $e->quantity_delta < 0 && $n <= $qty, 'Penerimaan campuran/transfer/opname belum mempunyai alokasi biaya terperinci.');
            $old = Proportion::floor($oldValue, $n, $qty);
            $new = Proportion::floor($newValue, $n, $qty);
            $this->require(-(int) $e->value_delta + ($e->source_type === 'pos_sale' ? ($saleDeltas->get($e->id) ?? 0) : ($movementDeltas->get($e->id) ?? 0)) === $old, 'Biaya historis tidak sesuai replay pool asal.');
            if ($e->source_type === 'pos_sale') {
                $s = $sales->get($e->source_id);
                $this->require($s && (int) $s->pieces === $n && ! isset($saleCosts[$e->source_id]), 'Alokasi POS asal tidak dapat dibuktikan.');
                $day = $this->day($s->sold_at);
                $event = $events->get('pos_sale:'.$s->id);
                if (! $event && $old === 0 && (int) $s->total_amount === 0 && (int) $s->discount_amount === 0) {
                    $checkDay($day);
                } else {
                    $checkEvent($event, 'cogs', $day);
                }
                $saleCosts[$e->source_id] = ['old' => $old, 'new' => $new, 'qty' => $n, 'sale_id' => $s->id];
                $delta = $new - $old;
                $cogs += $delta;
                $layers[] = ['id' => $e->id, 'delta' => $delta, 'role' => 'cogs', 'day' => $day];
            } else {
                $event = $events->get('stock_loss:'.$e->id);
                $this->require($event !== null, 'Transfer/pengganti/retur distribusi memerlukan penelusuran nilai tujuan.');
                $day = $this->day($e->created_at);
                $checkEvent($event, 'loss', $day);
                $waste = (int) ($wasteProof->get($e->source_type.':'.$e->source_id)?->pieces ?? 0);
                $this->require($waste >= $n, 'Bukti susut fisik tidak ditemukan.');
                $delta = $new - $old;
                $loss += $delta;
                $movements[] = ['id' => $e->id, 'delta' => $delta, 'role' => 'loss', 'day' => $day];
            }
            $qty -= $n;
            $oldValue -= $old;
            $newValue -= $new;
        }

        return ['evidence_bytes' => $evidenceBytes, 'quantity' => $qty, 'before' => $oldValue, 'after' => $newValue, 'cogs' => $cogs, 'loss' => $loss, 'sales' => $layers, 'movements' => $movements];
    }
}
