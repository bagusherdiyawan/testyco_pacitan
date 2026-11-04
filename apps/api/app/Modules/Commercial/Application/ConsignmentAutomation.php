<?php

namespace App\Modules\Commercial\Application;

use App\Modules\Commercial\CommercialServiceProvider;
use App\Modules\Commercial\Infrastructure\CommercialAgreement;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleLine;
use App\Modules\Sales\Infrastructure\SaleRefund;
use App\Shared\Domain\Proportion;
use Illuminate\Support\Facades\DB;

final class ConsignmentAutomation
{
    public function __construct(private CommercialJournal $journal) {}

    /** Actual credited batches and order IDs; no SKU-name/code-only attribution. */
    private function origins(array $batches, ?\DateTimeInterface $at = null): array
    {
        if (! $batches) {
            return [];
        }$rows = [];
        $first = DB::table('stock_entries as e')->join('shipment_lines as l', 'l.id', '=', 'e.source_id')->join('shipments as sh', 'sh.id', '=', 'l.shipment_id')->where('e.source_type', 'shipment_receipt')->where('e.quantity_pieces', '>', 0)->when($at, fn ($q) => $q->where('e.created_at', '<=', $at))->whereIn('e.inventory_batch_id', $batches)->select(['e.inventory_batch_id as batch', 'sh.supply_order_id as order_id', 'l.supply_order_line_id as order_line_id'])->distinct()->limit(2001)->get();
        $second = DB::table('stock_entries as e')->join('replacement_lines as l', 'l.id', '=', 'e.source_id')->join('shipment_lines as original', 'original.id', '=', 'l.original_shipment_line_id')->join('shipments as sh', 'sh.id', '=', 'original.shipment_id')->where('e.source_type', 'replacement_receipt')->where('e.quantity_pieces', '>', 0)->when($at, fn ($q) => $q->where('e.created_at', '<=', $at))->whereIn('e.inventory_batch_id', $batches)->select(['e.inventory_batch_id as batch', 'sh.supply_order_id as order_id', 'original.supply_order_line_id as order_line_id'])->distinct()->limit(2001)->get();
        abort_if($first->count() + $second->count() > 2000, 422, 'Terlalu banyak asal batch untuk otomatisasi.');
        foreach ([...$first, ...$second] as $r) {
            $rows[$r->batch][] = ['order_id' => $r->order_id, 'order_line_id' => $r->order_line_id];
        }

        return $rows;
    }

    public function guardDispatch(string $batch): void
    {
        $sources = $this->origins([$batch]);
        $orders = collect($sources)->flatten(1)->pluck('order_id')->all();
        abort_if(CommercialAgreement::whereIn('active_order_id', $orders)->whereIn('status', ['accepted', 'suspended'])->exists(), 409, 'Barang konsinyasi otomatis tetap di toko penerimaan asal; gunakan retur fisik atau kesepakatan perpindahan terpisah, jangan hilangkan jejak kewajiban.');
    }

    public function lockOrderBatches(string $order): void
    {
        $original = DB::table('stock_entries as e')->join('shipment_lines as l', 'l.id', '=', 'e.source_id')->join('shipments as sh', 'sh.id', '=', 'l.shipment_id')->where('e.source_type', 'shipment_receipt')->where('sh.supply_order_id', $order)->where('e.quantity_pieces', '>', 0)->pluck('e.inventory_batch_id');
        $replacements = DB::table('stock_entries as e')->join('replacement_lines as l', 'l.id', '=', 'e.source_id')->join('shipment_lines as original', 'original.id', '=', 'l.original_shipment_line_id')->join('shipments as sh', 'sh.id', '=', 'original.shipment_id')->where('e.source_type', 'replacement_receipt')->where('sh.supply_order_id', $order)->where('e.quantity_pieces', '>', 0)->pluck('e.inventory_batch_id');
        $ids = $original->merge($replacements)->unique()->values()->all();
        abort_if(count($ids) > 10000, 422, 'Terlalu banyak batch untuk aktivasi otomatis.');
        InventoryBatch::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    public function assertFreshOrder(string $order): void
    {
        $batches = DB::table('stock_entries as e')->join('shipment_lines as l', 'l.id', '=', 'e.source_id')->join('shipments as sh', 'sh.id', '=', 'l.shipment_id')->where('e.source_type', 'shipment_receipt')->where('sh.supply_order_id', $order)->where('e.quantity_pieces', '>', 0)->pluck('e.inventory_batch_id');
        $extra = DB::table('stock_entries as e')->join('replacement_lines as l', 'l.id', '=', 'e.source_id')->join('shipment_lines as original', 'original.id', '=', 'l.original_shipment_line_id')->join('shipments as sh', 'sh.id', '=', 'original.shipment_id')->where('e.source_type', 'replacement_receipt')->where('sh.supply_order_id', $order)->where('e.quantity_pieces', '>', 0)->pluck('e.inventory_batch_id');
        $batches = $batches->merge($extra)->unique();
        abort_if(DB::table('sale_allocations')->whereIn('inventory_batch_id', $batches)->exists(), 409, 'Aktifkan otomatisasi sebelum penjualan batch pertama; data lama perlu rekonsiliasi manual.');
    }

    public function guardSale(Sale $sale): void
    {
        foreach (SaleLine::where('sale_id', $sale->id)->with('allocations')->get() as $line) {
            $origins = $this->origins($line->allocations->pluck('inventory_batch_id')->all());
            $orders = collect($origins)->flatten(1)->pluck('order_id')->unique();
            $agreements = CommercialAgreement::whereIn('active_order_id', $orders)->whereIn('status', ['pending', 'accepted', 'suspended'])->orderBy('id')->get();
            abort_if($agreements->contains(fn ($g) => in_array($g->status, ['accepted', 'suspended'], true)) && ! app()->getProvider(CommercialServiceProvider::class), 409, 'Modul pembukuan konsinyasi nonaktif; rekonsiliasi diperlukan sebelum penjualan baru.');
            foreach ($agreements as $g) {
                $g = CommercialAgreement::lockForUpdate()->findOrFail($g->id);
                if (! in_array($g->status, ['accepted', 'suspended'], true)) {
                    continue;
                }
                abort_unless($g->status === 'accepted' || ($sale->offline_sale_id && $g->stopped_at && $sale->sold_at->lt($g->stopped_at)), 409, 'Otomatisasi sudah dihentikan untuk transaksi baru.');
                $day = $sale->sold_at->setTimezone('Asia/Jakarta')->format('Y-m-d');
                abort_unless($sale->sold_at->gte($g->activated_at) && (! $g->stopped_at || $sale->sold_at->lte($g->stopped_at)) && $day >= $g->starts_on->format('Y-m-d') && $day <= $g->ends_on->format('Y-m-d'), 409, 'Periode otomatisasi konsinyasi tidak berlaku; rekonsiliasi perjanjian sebelum menjual.');
                $this->freezeOrigins($sale, $line);
                $this->calculate($g, $sale, $line);
            }
        }
    }

    private function freezeOrigins(Sale $sale, SaleLine $line): void
    {
        $origins = $this->origins($line->allocations->pluck('inventory_batch_id')->all(), $sale->sold_at);
        foreach ($line->allocations as $a) {
            if ($a->commercial_origin_snapshot !== null) {
                continue;
            }
            $foreign = DB::table('stock_entries')->where('inventory_batch_id', $a->inventory_batch_id)->where('quantity_pieces', '>', 0)->whereIn('bucket', ['usable', 'quarantined'])->where('created_at', '<=', $sale->sold_at)->whereNotIn('source_type', ['shipment_receipt', 'replacement_receipt', 'sale_refund'])->exists();
            $a->forceFill(['commercial_origin_snapshot' => ['sources' => $origins[$a->inventory_batch_id] ?? [], 'unproven_credit' => $foreign, 'captured_at' => now()->toIso8601String()]])->save();
        }
    }

    private function calculate(CommercialAgreement $g, Sale $sale, SaleLine $line, ?array $origins = null): array
    {
        $day = $sale->sold_at->setTimezone('Asia/Jakarta')->format('Y-m-d');
        $order = SupplyOrder::findOrFail($g->supply_order_id);
        abort_unless($sale->organization_id === $g->partner_id && $sale->store_id === $order->store_id && $sale->sold_at->gte($g->activated_at) && (! $g->stopped_at || $sale->sold_at->lte($g->stopped_at)) && $day >= $g->starts_on->format('Y-m-d') && $day <= $g->ends_on->format('Y-m-d'), 409, 'Sumber, toko atau periode pembukuan tidak sesuai perjanjian.');
        $origins ??= $this->origins($line->allocations->pluck('inventory_batch_id')->all(), $sale->sold_at);
        foreach ($line->allocations as $a) {
            if ($a->commercial_origin_snapshot !== null) {
                $origins[$a->inventory_batch_id] = $a->commercial_origin_snapshot['sources'];
            }
        }
        $t = $g->terms;
        abort_if($line->recipe_snapshot && $t['bundle_basis'] === 'reject', 409, 'Perjanjian tidak menyetujui atribusi pendapatan paket.');
        $refundQty = 0;
        $refundMoney = 0;
        foreach (SaleRefund::where('sale_id', $sale->id)->get() as $refund) {
            foreach ($refund->lines as $rl) {
                if ($rl['sale_line_id'] === $line->id) {
                    $refundQty += (int) $rl['quantity'];
                    $refundMoney += (int) $rl['amount'];
                }
            }
        }
        $allocations = $line->allocations->sortBy('id')->values();
        $totalPieces = (int) $allocations->sum('pieces');
        abort_unless($totalPieces > 0, 409);
        $net = $line->line_total - $line->discount_amount;
        $shares = [];
        $left = $net;
        foreach ($allocations as $a) {
            $shares[$a->id] = Proportion::floor($net, $a->pieces, $totalPieces);
            $left -= $shares[$a->id];
        }
        foreach ($allocations as $a) {
            if (! $left) {
                break;
            }$shares[$a->id]++;
            $left--;
        }
        $products = DB::table('inventory_batches')->whereIn('id', $allocations->pluck('inventory_batch_id'))->pluck('product_id', 'id');
        $refunds = [];
        foreach ($allocations->groupBy(fn ($a) => $products[$a->inventory_batch_id]) as $group) {
            $n = (int) $group->sum('pieces');
            abort_unless($n % $line->quantity === 0, 409, 'Konversi refund historis perlu rekonsiliasi.');
            $left = $refundQty * intdiv($n, $line->quantity);
            foreach ($group as $a) {
                $take = min($left, $a->pieces);
                $refunds[$a->id] = $take;
                $left -= $take;
            }
        }
        $refundAmounts = [];
        $returned = array_sum($refunds);
        $left = $refundMoney;
        foreach ($allocations as $a) {
            $refundAmounts[$a->id] = $returned ? min($shares[$a->id], Proportion::floor($refundMoney, $refunds[$a->id] ?? 0, $returned)) : 0;
            $left -= $refundAmounts[$a->id];
        }
        foreach ($allocations as $a) {
            if (! $left) {
                break;
            }$take = min($left, $shares[$a->id] - $refundAmounts[$a->id]);
            $refundAmounts[$a->id] += $take;
            $left -= $take;
        }
        abort_unless($left === 0, 409, 'Atribusi nominal refund perlu rekonsiliasi.');
        $basis = 0;
        $cost = 0;
        $refunded = 0;
        $pieces = 0;
        $found = false;
        foreach ($allocations as $a) {
            $sources = $origins[$a->inventory_batch_id] ?? [];
            $orders = array_unique(array_column($sources, 'order_id'));
            if (! in_array($g->supply_order_id, $orders, true)) {
                continue;
            }$found = true;
            abort_unless(count($orders) === 1 && count(array_unique(array_column($sources, 'order_line_id'))) === 1, 409, 'Batch mencampur kepemilikan/baris pesanan; rekonsiliasi terpisah diperlukan.');
            $foreignCredit = $a->commercial_origin_snapshot !== null ? (bool) $a->commercial_origin_snapshot['unproven_credit'] : DB::table('stock_entries')->where('inventory_batch_id', $a->inventory_batch_id)->where('quantity_pieces', '>', 0)->whereIn('bucket', ['usable', 'quarantined'])->where('created_at', '<=', $sale->sold_at)->whereNotIn('source_type', ['shipment_receipt', 'replacement_receipt', 'sale_refund'])->exists();
            abort_if($foreignCredit, 409, 'Batch memiliki kredit asal lain; tidak boleh ditagih konsinyasi otomatis.');
            $r = min($a->pieces, $refunds[$a->id] ?? 0);
            $remaining = $a->pieces - $r;
            $pieces += $remaining;
            $refunded += $r;
            $basis += $shares[$a->id] - $refundAmounts[$a->id];
            if ($t['basis'] === 'margin') {
                $row = collect($t['costs'])->firstWhere('order_line_id', $sources[0]['order_line_id']);
                abort_unless($row, 409, 'HPP yang disepakati tidak ditemukan.');
                abort_unless($remaining === 0 || (int) $row['cost_per_piece'] <= intdiv(1000000000000, $remaining), 422, 'HPP melampaui batas nominal perhitungan.');
                $cost += $remaining * (int) $row['cost_per_piece'];
                abort_if($cost > 1000000000000, 422, 'HPP melampaui batas nominal perhitungan.');
            }
        }
        abort_unless($found, 409, 'Tidak ada alokasi kiriman perjanjian.');
        $profit = $basis - $cost;
        $shareBase = $t['basis'] === 'margin' ? ($t['loss_policy'] === 'zero_floor' ? max(0, $profit) : $profit) : $basis;
        $amount = Proportion::floor(abs($shareBase), (int) $t['supplier_basis_points'], 10000) * ($shareBase < 0 ? -1 : 1);
        $tax = Proportion::floor(abs($amount), (int) $t['tax_basis_points'], 10000) * ($amount < 0 ? -1 : 1);

        return ['basis' => $basis, 'amount' => $amount + $tax, 'refund_quantity' => $refunded, 'snapshot' => ['net_allocated_sales' => $basis, 'agreed_goods_cost' => $cost, 'remaining_pieces' => $pieces, 'share_before_tax' => $amount, 'tax_amount' => $tax, 'supplier_basis_points' => $t['supplier_basis_points'], 'retailer_retained_revenue_before_cost_tax' => $basis - $amount, 'retailer_profit_share_before_tax' => $t['basis'] === 'margin' ? $profit - $amount : null]];
    }

    public function accrue(int $limit = 100): array
    {
        $done = 0;
        $failed = 0;
        foreach (CommercialAgreement::where('kind', 'consignment')->whereIn('status', ['accepted', 'suspended'])->orderByRaw('CASE WHEN last_processed_at IS NULL THEN 0 ELSE 1 END')->orderBy('last_processed_at')->orderBy('id')->cursor() as $g) {
            if ($done + $failed >= $limit) {
                break;
            }
            try {
                DB::transaction(function () use ($g) {
                    $order = SupplyOrder::lockForUpdate()->findOrFail($g->supply_order_id);
                    $g = CommercialAgreement::lockForUpdate()->findOrFail($g->id);
                    $credited = DB::table('stock_entries as e')->join('shipment_lines as sl', 'sl.id', '=', 'e.source_id')->join('shipments as sh', 'sh.id', '=', 'sl.shipment_id')->where('e.source_type', 'shipment_receipt')->where('sh.supply_order_id', $g->supply_order_id)->pluck('e.inventory_batch_id');
                    $replaced = DB::table('stock_entries as e')->join('replacement_lines as rl', 'rl.id', '=', 'e.source_id')->join('shipment_lines as sl', 'sl.id', '=', 'rl.original_shipment_line_id')->join('shipments as sh', 'sh.id', '=', 'sl.shipment_id')->where('e.source_type', 'replacement_receipt')->where('sh.supply_order_id', $g->supply_order_id)->pluck('e.inventory_batch_id');
                    $batchIds = $credited->merge($replaced)->unique()->values()->all();
                    abort_if(count($batchIds) > 10000, 422, 'Terlalu banyak batch untuk satu perjanjian otomatis.');
                    $lines = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->leftJoin('commercial_positions as p', function ($j) use ($g) {
                        $j->on('p.sale_line_id', '=', 'l.id')->where('p.commercial_agreement_id', $g->id);
                    })->where('s.organization_id', $g->partner_id)->where('s.store_id', $order->store_id)->where('s.sold_at', '>=', $g->activated_at)->whereExists(fn ($q) => $q->selectRaw('1')->from('sale_allocations as a')->whereColumn('a.sale_line_id', 'l.id')->whereIn('a.inventory_batch_id', $batchIds))->where(fn ($q) => $q->whereNull('p.id')->orWhereColumn('p.sale_version', '<>', 's.version'))->orderBy('l.id')->limit(500)->get(['l.id', 'l.sale_id']);
                    foreach ($lines as $candidate) {
                        $sale = Sale::findOrFail($candidate->sale_id);
                        $line = SaleLine::with('allocations')->findOrFail($candidate->id);
                        $origins = $this->origins($line->allocations->pluck('inventory_batch_id')->all(), $sale->sold_at);
                        $orders = collect($origins)->flatten(1)->pluck('order_id')->all();
                        if (! in_array($g->supply_order_id, $orders, true)) {
                            continue;
                        }$x = $this->calculate($g, $sale, $line, $origins);
                        $this->journal->project($g, 'line:'.$line->id, $sale, $line->id, $x['basis'], $x['amount'], $x['refund_quantity'], $x['snapshot']);
                    }
                    $g->last_error_code = null;
                    $g->last_processed_at = now();
                    $g->save();
                });
                $done++;
            } catch (\Throwable) {
                CommercialAgreement::whereKey($g->id)->update(['last_error_code' => 'review_required', 'last_processed_at' => now()]);
                $failed++;
            }
        }

        return compact('done', 'failed');
    }
}
