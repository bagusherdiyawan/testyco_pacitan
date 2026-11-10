<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Billing\Infrastructure\Invoice;
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

final class InvoiceValuation
{
    private function day(mixed $date): string
    {
        return CarbonImmutable::parse($date, 'UTC')->setTimezone('Asia/Jakarta')->format('Y-m-d');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new DomainException($message);
        }
    }

    private function open(string $org, string $day): void
    {
        $this->require(DB::table('accounting_periods')->where('organization_id', $org)->where('status', 'open')->where('starts_on', '<=', $day)->where('ends_on', '>=', $day)->exists(), 'Periode sumber '.$day.' tertutup/tidak tersedia; pengelola harus meninjau dan membuka periode asal sebelum mengantrekan ulang.');
    }

    private function hasNetEffect(array $terms): bool
    {
        $balances = [];
        foreach ($terms as [$debit, $credit, $amount]) {
            $balances[$debit] = ($balances[$debit] ?? 0) + $amount;
            $balances[$credit] = ($balances[$credit] ?? 0) - $amount;
        }

        return count(array_filter($balances, fn ($n) => $n !== 0)) > 0;
    }

    public function capture(Invoice $invoice, object $policy, int $actor): void
    {
        if ($invoice->mode !== 'buyout' || $invoice->status !== 'accepted') {
            return;
        }
        $ids = array_column($invoice->lines, 'shipment_line_id');
        $receiptEvents = DB::table('accounting_events')->where('organization_id', $invoice->partner_id)->where('source_type', 'distribution_receipt')->whereIn('source_id', $ids)->get();
        if ($receiptEvents->isEmpty()) {
            return;
        }
        $day = max($this->day($invoice->decided_at), $receiptEvents->min('event_on'));
        DB::table('accounting_invoice_revaluations')->insertOrIgnore(['id' => (string) Str::ulid(), 'organization_id' => $invoice->partner_id, 'invoice_id' => $invoice->id, 'policy_id' => $policy->id, 'event_on' => $day, 'status' => 'pending', 'version' => 1, 'actor_id' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        $state = DB::table('accounting_invoice_revaluations')->where('organization_id', $invoice->partner_id)->where('invoice_id', $invoice->id)->firstOrFail();
        if ($state->status !== 'applied') {
            DB::table('accounting_invoice_revaluations')->where('id', $state->id)->update(['event_on' => min($state->event_on, $day), 'updated_at' => now()]);
            $this->apply($state->id);
        }
    }

    private function freeInvoice(Invoice $invoice): bool
    {
        return $invoice->total_amount === 0 && $invoice->subtotal - $invoice->discount_amount === 0 && $invoice->shipping_amount === 0 && $invoice->tax_amount === 0;
    }

    public function captureReceived(string $org, string $line, int $actor): void
    {
        $invoices = Invoice::where('partner_id', $org)->where('status', 'accepted')->whereIn('id', DB::table('invoice_shipment_lines')->where('shipment_line_id', $line)->select('invoice_id'))->get();
        foreach ($invoices as $invoice) {
            $origin = DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'wholesale_invoice')->where('source_id', $invoice->id)->first();
            if (! $origin && $this->freeInvoice($invoice)) {
                $origin = DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'distribution_receipt')->where('source_id', $line)->first();
            }
            if ($origin) {
                $this->capture($invoice, (object) ['id' => $origin->policy_id], $actor);
            }
        }
    }

    public function retry(string $org, array $v): array
    {
        $s = (array) DB::table('accounting_invoice_revaluations')->where('organization_id', $org)->where('id', $v['id'])->lockForUpdate()->firstOrFail();
        abort_unless($s['status'] === 'review_required' && $s['version'] === $v['version'], 409);
        $n = ['status' => 'pending', 'version' => $s['version'] + 1, 'note' => $v['note'], 'updated_at' => now()];
        DB::table('accounting_invoice_revaluations')->where('id', $s['id'])->update($n);

        return [...$s, ...$n];
    }

    public function drain(int $limit): int
    {
        $done = 0;
        foreach (DB::table('accounting_invoice_revaluations')->where('status', 'pending')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            if ($this->apply($id)) {
                $done++;
            }
        }

        return $done;
    }

    public function apply(string $id): bool
    {
        return DB::transaction(function () use ($id) {
            $seed = DB::table('accounting_invoice_revaluations')->where('id', $id)->firstOrFail();
            $invoice = Invoice::where('partner_id', $seed->organization_id)->findOrFail($seed->invoice_id);
            SupplyOrder::whereKey($invoice->supply_order_id)->lockForUpdate()->firstOrFail();
            $s = DB::table('accounting_invoice_revaluations')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($s->status === 'applied') {
                return false;
            }
            try {
                $plan = $this->plan($invoice, $s);
            } catch (DomainException $e) {
                DB::table('accounting_invoice_revaluations')->where('id', $id)->update(['status' => 'review_required', 'reason' => $e->getMessage(), 'version' => $s->version + 1, 'updated_at' => now()]);
                Audit::record($s->actor_id, $s->organization_id, 'accounting.invoice_valuation_review', $id, null, ['automatic' => true, 'invoice_id' => $invoice->id, 'reason' => $e->getMessage(), 'version' => $s->version + 1]);

                return false;
            }
            $termsByDay = [];
            $later = [];
            $stock = $cogs = $loss = 0;
            foreach ($plan as $a) {
                $variance = (string) Str::ulid();
                DB::table('inventory_invoice_variances')->insert(['id' => $variance, 'revaluation_id' => $id, 'organization_id' => $s->organization_id, 'invoice_id' => $invoice->id, 'shipment_line_id' => $a['line'], 'inventory_batch_id' => $a['batch']->id, 'stock_revision' => $a['batch']->stock_revision, 'original_value' => $a['original'], 'final_value' => $a['final'], 'remaining_quantity' => $a['remaining'], 'before_value' => $a['before'], 'after_value' => $a['after'], 'stock_adjustment' => $a['stock'], 'cogs_adjustment' => $a['cogs'], 'loss_adjustment' => $a['loss'], 'created_at' => now()]);
                foreach ($a['receipts'] as $proof) {
                    DB::table('inventory_invoice_receipt_adjustments')->insert(['id' => (string) Str::ulid(), 'organization_id' => $s->organization_id, 'variance_id' => $variance, 'cost_entry_id' => $proof['id'], 'shipment_line_id' => $proof['line'], 'before_value' => $proof['before'], 'after_value' => $proof['after'], 'value_delta' => $proof['after'] - $proof['before'], 'created_at' => now()]);
                }
                if ($a['stock'] !== 0) {
                    app(StockLedger::class)->apply($a['batch'], $s->actor_id, [], 'invoice_variance', $variance, $invoice->number, valueDelta: $a['stock']);
                }
                foreach ($a['sales'] as $sale) {
                    if ($sale['delta'] !== 0) {
                        DB::table('inventory_invoice_sale_adjustments')->insert(['id' => (string) Str::ulid(), 'variance_id' => $variance, 'organization_id' => $s->organization_id, 'cost_entry_id' => $sale['id'], 'cost_delta' => $sale['delta'], 'created_at' => now()]);
                    }
                }
                foreach ($a['movements'] as $movement) {
                    if ($movement['delta'] !== 0) {
                        DB::table('inventory_invoice_movement_adjustments')->insert(['id' => (string) Str::ulid(), 'variance_id' => $variance, 'organization_id' => $s->organization_id, 'cost_entry_id' => $movement['id'], 'expense_role' => $movement['role'], 'event_on' => $movement['effective_on'], 'cost_delta' => $movement['delta'], 'created_at' => now()]);
                    }
                }
                foreach ($a['components'] as $component) {
                    $late = ['cogs' => 0, 'loss' => 0];
                    foreach ([...$component['sales'], ...$component['movements']] as $movement) {
                        if ($movement['day'] > $component['day'] && $movement['delta'] !== 0) {
                            $d = $movement['delta'];
                            $role = $movement['role'];
                            $late[$role] += $d;
                            $later[$movement['day']][] = $d > 0 ? [$role, 'finished', $d] : ['finished', $role, -$d];
                        }
                    }
                    foreach ([['finished', $component['stock'] + array_sum($late)], ['cogs', $component['cogs'] - $late['cogs']], ['loss', $component['loss'] - $late['loss']]] as [$role,$delta]) {
                        if ($delta !== 0) {
                            $termsByDay[$component['day']][] = $delta > 0 ? [$role, 'purchase_clearing', $delta] : ['purchase_clearing', $role, -$delta];
                        }
                    }
                }
                $stock += $a['stock'];
                $cogs += $a['cogs'];
                $loss += $a['loss'];
            }
            ksort($termsByDay);
            ksort($later);
            foreach ($termsByDay as $day => $terms) {
                if (! $this->hasNetEffect($terms)) {
                    continue;
                }
                app(AutomaticPosting::class)->record($s->organization_id, $s->actor_id, 'invoice_variance', $day === $s->event_on ? $id : $id.':'.$day, $day, $terms, $invoice->number.' · penyesuaian biaya final', null, $s->policy_id);
            }
            foreach ($later as $day => $depletion) {
                if (! $this->hasNetEffect($depletion)) {
                    continue;
                }
                app(AutomaticPosting::class)->record($s->organization_id, $s->actor_id, 'invoice_variance_depletion', $id.':'.$day, $day, $depletion, $invoice->number.' · koreksi HPP penjualan sesudah invoice', null, $s->policy_id);
            }
            DB::table('accounting_invoice_revaluations')->where('id', $id)->update(['status' => 'applied', 'reason' => null, 'stock_adjustment' => $stock, 'cogs_adjustment' => $cogs, 'loss_adjustment' => $loss, 'version' => $s->version + 1, 'updated_at' => now()]);
            Audit::record($s->actor_id, $s->organization_id, 'accounting.invoice_valued', $id, null, ['automatic' => true, 'invoice_id' => $invoice->id, 'policy_id' => $s->policy_id, 'stock_adjustment' => $stock, 'cogs_adjustment' => $cogs, 'loss_adjustment' => $loss, 'version' => $s->version + 1]);

            return true;
        }, 3);
    }

    private function plan(Invoice $invoice, object $s): array
    {
        $org = $s->organization_id;
        $this->require($invoice->mode === 'buyout' && $invoice->status === 'accepted', 'Invoice bukan putus beli yang diterima.');
        $origin = DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'wholesale_invoice')->where('source_id', $invoice->id)->first();
        $this->require(($origin && ! in_array($origin->status, ['reconciled', 'review_required'], true)) || (! $origin && $this->freeInvoice($invoice)), 'Jurnal invoice asal perlu rekonsiliasi sebelum nilai stok dikoreksi.');
        $p = DB::table('accounting_policies')->where('organization_id', $org)->where('id', $s->policy_id)->whereNotNull('activated_at')->first();
        $settings = DB::table('accounting_wholesale_policies')->where('organization_id', $org)->where('policy_id', $s->policy_id)->first();
        $this->require($p && $settings && $settings->invoice_variance_basis === 'remaining_stock_and_historical_cogs', 'Pemetaan asal tidak mengaktifkan alokasi selisih invoice.');
        $mapping = json_decode($p->mapping, true);
        $capital = $invoice->subtotal - $invoice->discount_amount;
        if ($settings->freight_treatment === 'clearing') {
            $capital = Rupiah::add($capital, $invoice->shipping_amount);
        }
        if ($settings->tax_treatment === 'clearing') {
            $capital = Rupiah::add($capital, $invoice->tax_amount);
        }
        $this->require(count($invoice->lines) <= 100 && $invoice->subtotal > 0, 'Alokasi invoice maksimal100baris dan subtotal positif.');
        $receiptIds = array_column($invoice->lines, 'shipment_line_id');
        $receiptRows = DB::table('inventory_cost_entries')->where('organization_id', $org)->where('source_type', 'shipment_receipt')->whereIn('source_id', $receiptIds)->where('quantity_delta', '>', 0)->limit(101)->get();
        if ($capital === $invoice->subtotal) {
            $unchanged = true;
            foreach ($invoice->lines as $line) {
                if ((int) $line['line_total'] === 0) {
                    continue;
                }
                $rr = $receiptRows->where('source_id', $line['shipment_line_id']);
                $re = DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'distribution_receipt')->where('source_id', $line['shipment_line_id'])->first();
                if ($rr->count() !== 1 || ! $re || $re->policy_id !== $s->policy_id || in_array($re->status, ['reconciled', 'review_required'], true) || (int) $rr->first()->quantity_delta !== (int) $line['quantity_pieces'] || $rr->first()->value_delta === null || (int) $rr->first()->value_delta !== (int) $line['line_total']) {
                    $unchanged = false;
                    break;
                }
            }
            if ($unchanged) {
                return [];
            }
        }
        $this->open($org, $s->event_on);

        $batchIds = $receiptRows->pluck('inventory_batch_id')->unique()->sort()->values()->all();
        $batches = InventoryBatch::where('organization_id', $org)->whereIn('id', $batchIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $out = [];
        $groups = [];
        $weight = 0;
        $allocated = 0;
        $gross = 0;
        $laterGross = [];
        $entryCount = 0;
        $entryBytes = 0;
        $checkedDays = [$s->event_on => true];
        $checkDay = function (string $day) use ($org, &$checkedDays): void {
            if (! isset($checkedDays[$day])) {
                $this->open($org, $day);
                $checkedDays[$day] = true;
            }
        };
        foreach ($invoice->lines as $line) {
            if ((int) $line['line_total'] === 0) {
                continue;
            }
            $weight += (int) $line['line_total'];
            $next = Proportion::floor($capital, $weight, $invoice->subtotal);
            $final = $next - $allocated;
            $allocated = $next;
            $receipts = $receiptRows->where('source_id', $line['shipment_line_id']);
            $this->require($receipts->count() === 1, 'Dasar nilai penerimaan belum lengkap atau memiliki beberapa bucket/sumber; perlu alokasi terperinci.');
            $receipt = $receipts->first();
            $batch = $batches[$receipt->inventory_batch_id] ?? null;
            $this->require($batch && $receipt->value_delta !== null && (int) $receipt->quantity_delta === (int) $line['quantity_pieces'], 'Batch campuran atau jumlah invoice berbeda dari penerimaan; perlu rekonsiliasi.');
            $receiptEvent = DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'distribution_receipt')->where('source_id', $line['shipment_line_id'])->first();
            $this->require($receiptEvent && $receiptEvent->policy_id === $s->policy_id && ! in_array($receiptEvent->status, ['reconciled', 'review_required'], true), 'Pemetaan/jurnal penerimaan asal berbeda atau perlu ditinjau.');
            $checkDay($receiptEvent->event_on);
            $effectiveDay = max($this->day($invoice->decided_at), $receiptEvent->event_on);
            $checkDay($effectiveDay);
            if (! isset($groups[$batch->id])) {
                $groups[$batch->id] = ['batch' => $batch, 'day' => $effectiveDay, 'line' => $line['shipment_line_id'], 'targets' => [], 'lines' => [], 'days' => []];
            }
            $groups[$batch->id]['day'] = min($groups[$batch->id]['day'], $effectiveDay);
            $groups[$batch->id]['days'][$receipt->id] = $effectiveDay;
            $this->require(! array_key_exists($receipt->id, $groups[$batch->id]['targets']), 'Sumber penerimaan diulang dalam invoice.');
            $groups[$batch->id]['targets'][$receipt->id] = $final;
            $groups[$batch->id]['lines'][$receipt->id] = $line['shipment_line_id'];
        }
        foreach ($groups as $group) {
            $batch = $group['batch'];
            $effectiveDay = $group['day'];
            $entries = DB::table('inventory_cost_entries')->where('organization_id', $org)->where('inventory_batch_id', $batch->id)->orderBy('id')->limit(10001 - $entryCount)->get();
            $entryCount += $entries->count();
            $entryBytes += strlen(json_encode($entries));
            $this->require($entryCount <= 10000 && $entryBytes <= 8 * 1024 * 1024, 'Jejak invoice melampaui10.000entri/8MiB; perlu alokasi terperinci.');
            $pool = DB::table('inventory_values')->where('organization_id', $org)->where('inventory_batch_id', $batch->id)->lockForUpdate()->first();
            $this->require($pool && $pool->remaining_value !== null && (int) $pool->quantity === app(InventoryValuation::class)->quantity($batch), 'Pool stok tidak diketahui atau perlu rekonsiliasi jumlah.');
            $history = app(ReceiptValueHistory::class)->read($org, $batch->id, $entries, $mapping);
            $entryBytes += $history['evidence_bytes'];
            $original = $final = 0;
            $receiptProofs = [];
            foreach ($group['targets'] as $receiptId => $target) {
                $this->require(array_key_exists($receiptId, $history['prices']), 'Harga sumber invoice belum terbukti.');
                $before = $history['prices'][$receiptId];
                $original = Rupiah::add($original, $before);
                $final = Rupiah::add($final, $target);
                $receiptProofs[] = ['id' => $receiptId, 'line' => $group['lines'][$receiptId], 'before' => $before, 'after' => $target];
            }
            $targetsByDay = [];
            foreach ($group['targets'] as $receiptId => $target) {
                $targetsByDay[$group['days'][$receiptId]][$receiptId] = $target;
            }
            ksort($targetsByDay);
            $cumulativeTargets = [];
            $components = [];
            $previous = ['after' => (int) $pool->remaining_value, 'cogs' => 0, 'loss' => 0];
            $previousMovements = [];
            $movementDays = [];
            foreach ($targetsByDay as $day => $targets) {
                $cumulativeTargets += $targets;
                $replay = app(InvoiceCostReplay::class)->plan($org, $batch->id, $entries, $history['prices'], $cumulativeTargets, $history['variances']->all(), $mapping, $checkDay);
                $entryBytes += $replay['evidence_bytes'];
                $this->require($entryBytes <= 8 * 1024 * 1024, 'Bukti invoice melampaui8MiB; perlu rekonsiliasi terperinci.');
                $this->require($replay['quantity'] === (int) $pool->quantity && $replay['before'] === (int) $pool->remaining_value, 'Jumlah/nilai historis batch tidak konservatif; perlu rekonsiliasi.');
                $component = ['day' => $day, 'stock' => $replay['after'] - $previous['after'], 'cogs' => $replay['cogs'] - $previous['cogs'], 'loss' => $replay['loss'] - $previous['loss'], 'sales' => [], 'movements' => []];
                foreach (['sales', 'movements'] as $key) {
                    foreach ($replay[$key] as $row) {
                        $delta = $row['delta'] - ($previousMovements[$row['id']] ?? 0);
                        $previousMovements[$row['id']] = $row['delta'];
                        $component[$key][] = [...$row, 'delta' => $delta];
                        if ($delta !== 0) {
                            $movementDays[$row['id']] = min($movementDays[$row['id']] ?? '9999-12-31', max($day, $row['day']));
                        }
                    }
                }
                $pricedDelta = 0;
                foreach ($targets as $receiptId => $target) {
                    $pricedDelta += $target - $history['prices'][$receiptId];
                }
                $this->require($pricedDelta === $component['stock'] + $component['cogs'] + $component['loss'], 'Alokasi tanggal koreksi tidak konservatif.');
                $components[] = $component;
                $previous = $replay;
            }
            $after = $replay['after'];
            $stock = $after - (int) $pool->remaining_value;
            $cogs = $replay['cogs'];
            $loss = $replay['loss'];
            $sales = $replay['sales'];
            $movements = $replay['movements'];
            $this->require($stock + $cogs + $loss === $final - $original, 'Alokasi koreksi tidak konservatif.');
            foreach ($movements as &$movement) {
                $movement['effective_on'] = $movementDays[$movement['id']] ?? max($effectiveDay, $movement['day']);
            }
            unset($movement);
            foreach ($components as $component) {
                $late = ['cogs' => 0, 'loss' => 0];
                foreach ([...$component['sales'], ...$component['movements']] as $movement) {
                    if ($movement['day'] > $component['day']) {
                        $late[$movement['role']] += $movement['delta'];
                        $laterGross[$movement['day']] = ($laterGross[$movement['day']] ?? 0) + abs($movement['delta']);
                    }
                }
                $gross += abs($component['stock'] + array_sum($late)) + abs($component['cogs'] - $late['cogs']) + abs($component['loss'] - $late['loss']);
            }
            $out[] = ['day' => $effectiveDay, 'line' => $group['line'], 'receipts' => $receiptProofs, 'batch' => $batch, 'original' => $original, 'final' => $final, 'remaining' => (int) $pool->quantity, 'before' => (int) $pool->remaining_value, 'after' => $after, 'stock' => $stock, 'cogs' => $cogs, 'loss' => $loss, 'sales' => $sales, 'movements' => $movements, 'components' => $components];
        }
        $this->require($weight === $invoice->subtotal && $allocated === $capital && $gross <= Rupiah::LIMIT && (! $laterGross || max($laterGross) <= Rupiah::LIMIT), 'Alokasi subtotal atau batas jurnal tidak sesuai.');

        return $out;
    }
}
