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
            $stock = $cogs = 0;
            foreach ($plan as $a) {
                $variance = (string) Str::ulid();
                DB::table('inventory_invoice_variances')->insert(['id' => $variance, 'revaluation_id' => $id, 'organization_id' => $s->organization_id, 'invoice_id' => $invoice->id, 'shipment_line_id' => $a['line'], 'inventory_batch_id' => $a['batch']->id, 'stock_revision' => $a['batch']->stock_revision, 'original_value' => $a['original'], 'final_value' => $a['final'], 'remaining_quantity' => $a['remaining'], 'before_value' => $a['before'], 'after_value' => $a['after'], 'stock_adjustment' => $a['stock'], 'cogs_adjustment' => $a['cogs'], 'created_at' => now()]);
                if ($a['stock'] !== 0) {
                    app(StockLedger::class)->apply($a['batch'], $s->actor_id, [], 'invoice_variance', $variance, $invoice->number, valueDelta: $a['stock']);
                }
                foreach ($a['sales'] as $sale) {
                    if ($sale['delta'] !== 0) {
                        DB::table('inventory_invoice_sale_adjustments')->insert(['id' => (string) Str::ulid(), 'variance_id' => $variance, 'organization_id' => $s->organization_id, 'cost_entry_id' => $sale['id'], 'cost_delta' => $sale['delta'], 'created_at' => now()]);
                    }
                }
                $late = 0;
                foreach ($a['sales'] as $sale) {
                    if ($sale['day'] > $a['day'] && $sale['delta'] !== 0) {
                        $d = $sale['delta'];
                        $late += $d;
                        $later[$sale['day']][] = $d > 0 ? ['cogs', 'finished', $d] : ['finished', 'cogs', -$d];
                    }
                }
                foreach ([['finished', $a['stock'] + $late], ['cogs', $a['cogs'] - $late]] as [$role,$delta]) {
                    if ($delta !== 0) {
                        $termsByDay[$a['day']][] = $delta > 0 ? [$role, 'purchase_clearing', $delta] : ['purchase_clearing', $role, -$delta];
                    }
                }
                $stock += $a['stock'];
                $cogs += $a['cogs'];
            }
            ksort($termsByDay);
            ksort($later);
            foreach ($termsByDay as $day => $terms) {
                app(AutomaticPosting::class)->record($s->organization_id, $s->actor_id, 'invoice_variance', $day === $s->event_on ? $id : $id.':'.$day, $day, $terms, $invoice->number.' · penyesuaian biaya final', null, $s->policy_id);
            }
            foreach ($later as $day => $depletion) {
                app(AutomaticPosting::class)->record($s->organization_id, $s->actor_id, 'invoice_variance_depletion', $id.':'.$day, $day, $depletion, $invoice->number.' · koreksi HPP penjualan sesudah invoice', null, $s->policy_id);
            }
            DB::table('accounting_invoice_revaluations')->where('id', $id)->update(['status' => 'applied', 'reason' => null, 'stock_adjustment' => $stock, 'cogs_adjustment' => $cogs, 'version' => $s->version + 1, 'updated_at' => now()]);
            Audit::record($s->actor_id, $s->organization_id, 'accounting.invoice_valued', $id, null, ['automatic' => true, 'invoice_id' => $invoice->id, 'policy_id' => $s->policy_id, 'stock_adjustment' => $stock, 'cogs_adjustment' => $cogs, 'version' => $s->version + 1]);

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
        $used = [];
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
            $this->require($batch && ! isset($used[$batch->id]) && $receipt->value_delta !== null && (int) $receipt->quantity_delta === (int) $line['quantity_pieces'], 'Batch campuran atau jumlah invoice berbeda dari penerimaan; perlu rekonsiliasi.');
            $used[$batch->id] = true;
            $receiptEvent = DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'distribution_receipt')->where('source_id', $line['shipment_line_id'])->first();
            $this->require($receiptEvent && $receiptEvent->policy_id === $s->policy_id && ! in_array($receiptEvent->status, ['reconciled', 'review_required'], true), 'Pemetaan/jurnal penerimaan asal berbeda atau perlu ditinjau.');
            $checkDay($receiptEvent->event_on);
            $effectiveDay = max($this->day($invoice->decided_at), $receiptEvent->event_on);
            $checkDay($effectiveDay);
            $entries = DB::table('inventory_cost_entries')->where('organization_id', $org)->where('inventory_batch_id', $batch->id)->orderBy('id')->limit(10001 - $entryCount)->get();
            $entryCount += $entries->count();
            $entryBytes += strlen(json_encode($entries));
            $this->require($entryCount <= 10000 && $entryBytes <= 8 * 1024 * 1024, 'Jejak invoice melampaui10.000entri/8MiB; perlu alokasi terperinci.');
            $pool = DB::table('inventory_values')->where('organization_id', $org)->where('inventory_batch_id', $batch->id)->lockForUpdate()->first();
            $this->require($pool && $pool->remaining_value !== null && (int) $pool->quantity === app(InventoryValuation::class)->quantity($batch), 'Pool stok tidak diketahui atau perlu rekonsiliasi jumlah.');
            $allocationIds = $entries->where('source_type', 'pos_sale')->pluck('source_id')->unique()->all();
            $sourceSales = DB::table('sale_allocations as a')->join('sale_lines as l', 'l.id', '=', 'a.sale_line_id')->join('sales as s', 's.id', '=', 'l.sale_id')->where('s.organization_id', $org)->where('a.inventory_batch_id', $batch->id)->whereIn('a.id', $allocationIds)->select('a.id as allocation_id', 's.id', 's.sold_at', 's.total_amount', 's.discount_amount')->get()->keyBy('allocation_id');
            $saleEvents = DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'pos_sale')->whereIn('source_id', $sourceSales->pluck('id')->unique()->all())->get(['source_id', 'policy_id', 'status'])->keyBy('source_id');
            $policyRows = DB::table('accounting_policies')->where('organization_id', $org)->whereIn('id', $saleEvents->pluck('policy_id')->unique()->all())->limit(1001)->get(['id', 'mapping']);
            $entryBytes += strlen(json_encode($sourceSales)) + strlen(json_encode($saleEvents)) + strlen(json_encode($policyRows));
            $this->require($policyRows->count() <= 1000 && $entryBytes <= 8 * 1024 * 1024, 'Bukti invoice/pemetaan melampaui8MiB/1000pemetaan; perlu rekonsiliasi terperinci.');
            $saleMappings = [];
            foreach ($policyRows as $row) {
                $m = json_decode($row->mapping, true);
                $saleMappings[$row->id] = ['cogs' => $m['cogs']['account_id'], 'finished' => $m['finished']['account_id']];
            }
            $sales = [];
            $soldQty = 0;
            $soldCost = 0;
            foreach ($entries as $e) {
                if ($e->id === $receipt->id || (int) $e->quantity_delta === 0) {
                    continue;
                }
                $this->require($e->source_type === 'pos_sale' && (int) $e->quantity_delta < 0 && $e->value_delta !== null, 'Batch memiliki retur, waste, transfer atau penerimaan campuran; perlu alokasi terperinci.');
                $sale = $sourceSales->get($e->source_id);
                $this->require($sale !== null, 'Alokasi POS asal tidak dapat dibuktikan.');
                $checkDay($this->day($sale->sold_at));
                $saleEvent = $saleEvents->get($sale->id);
                $this->require(($saleEvent && in_array($saleEvent->status, ['pending', 'posted'], true)) || (! $saleEvent && (int) $e->value_delta === 0 && (int) $sale->total_amount === 0 && (int) $sale->discount_amount === 0), 'Jurnal POS asal belum tersedia atau perlu rekonsiliasi.');
                if ($saleEvent) {
                    $sm = $saleMappings[$saleEvent->policy_id] ?? null;
                    $this->require($sm && $sm['cogs'] === $mapping['cogs']['account_id'] && $sm['finished'] === $mapping['finished']['account_id'], 'Akun HPP/persediaan POS berbeda; perlu rekonsiliasi pemetaan.');
                }
                $qty = -(int) $e->quantity_delta;
                $soldQty += $qty;
                $soldCost -= (int) $e->value_delta;
                $sales[] = ['id' => $e->id, 'qty' => $qty, 'cost' => -(int) $e->value_delta, 'delta' => 0, 'day' => $this->day($sale->sold_at)];
            }
            $initial = (int) $receipt->quantity_delta;
            $original = (int) $receipt->value_delta;
            $this->require($soldQty + (int) $pool->quantity === $initial && $soldCost + (int) $pool->remaining_value === $original, 'Jumlah/nilai historis batch tidak konservatif; perlu rekonsiliasi.');
            $after = (int) $pool->remaining_value;
            $newSold = 0;
            $cumulative = 0;
            if ($final !== $original) {
                foreach ($sales as &$a) {
                    $cumulative += $a['qty'];
                    $slice = Proportion::floor($final, $cumulative, $initial) - $newSold;
                    $newSold += $slice;
                    $a['delta'] = $slice - $a['cost'];
                }unset($a);
                $after = $final - $newSold;
            }
            $stock = $after - (int) $pool->remaining_value;
            $cogs = $final === $original ? 0 : $newSold - $soldCost;
            $late = 0;
            foreach ($sales as $sale) {
                if ($sale['day'] > $effectiveDay) {
                    $late += $sale['delta'];
                    $laterGross[$sale['day']] = ($laterGross[$sale['day']] ?? 0) + abs($sale['delta']);
                }
            }$gross += abs($stock + $late) + abs($cogs - $late);
            $out[] = ['day' => $effectiveDay, 'line' => $line['shipment_line_id'], 'batch' => $batch, 'original' => $original, 'final' => $final, 'remaining' => (int) $pool->quantity, 'before' => (int) $pool->remaining_value, 'after' => $after, 'stock' => $stock, 'cogs' => $cogs, 'sales' => $sales];
        }
        $this->require($weight === $invoice->subtotal && $allocated === $capital && $gross <= Rupiah::LIMIT && (! $laterGross || max($laterGross) <= Rupiah::LIMIT), 'Alokasi subtotal atau batas jurnal tidak sesuai.');

        return $out;
    }
}
