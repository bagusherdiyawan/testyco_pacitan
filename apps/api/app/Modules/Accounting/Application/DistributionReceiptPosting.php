<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Fulfillment\Infrastructure\ShipmentLine;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Shared\Domain\Proportion;
use App\Shared\Domain\Rupiah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DistributionReceiptPosting
{
    private function policy(string $org, ?string $policyId = null): ?object
    {
        $q = DB::table('accounting_policies as p')->join('accounting_wholesale_policies as w', 'w.policy_id', '=', 'p.id')->where('p.organization_id', $org)->where('w.organization_id', $org)->where('w.ownership_basis', 'receipt_confirmed')->where('w.provisional_basis', 'approved_order')->whereNotNull('p.activated_at');

        return ($policyId ? $q->where('p.id', $policyId) : $q->where('p.status', 'active'))->select('p.id', 'p.mapping')->first();
    }

    public function eligible(SupplyOrder $order, ShipmentLine $line, int $good, int $damaged): bool
    {
        if ($order->kind !== 'wholesale' || $good < 0 || $damaged < 0 || $good + $damaged > $line->quantity_packs) {
            return false;
        }
        if (DB::table('commercial_agreements')->where('active_order_id', $order->id)->exists() || DB::table('consignment_reports')->where('supply_order_id', $order->id)->whereIn('status', ['pending', 'accepted'])->exists()) {
            return false;
        }
        $buyout = DB::table('invoices')->where('supply_order_id', $order->id)->where('mode', 'buyout')->where('status', 'accepted')->exists();

        return $buyout || ! DB::table('partnership_contracts')->where('organization_id', $order->supplier_organization_id)->where('partner_id', $order->organization_id)->where('mode', 'consignment')->where('status', 'accepted')->exists();
    }

    /** @return array{value:?int,policy_id:?string,bucket_values:array} */
    public function quote(SupplyOrder $order, ShipmentLine $line, int $good, int $damaged, int $unitPrice): array
    {
        $none = ['value' => null, 'policy_id' => null, 'bucket_values' => ['usable' => null, 'quarantined' => null]];
        if ($order->kind === 'internal_transfer' && $order->supplier_organization_id === $order->organization_id && $good >= 0 && $damaged >= 0 && $good + $damaged <= $line->quantity_packs) {
            $cost = DB::table('inventory_cost_entries')->where('organization_id', $order->organization_id)->where('source_type', 'shipment_dispatch')->where('source_id', $line->id)->first();
            $known = $cost && $cost->value_delta !== null && $cost->value_delta <= 0 && -(int) $cost->quantity_delta === $line->quantity_packs * $line->pack_size;
            if (! $known) {
                return $none;
            }
            $value = Proportion::floor(-(int) $cost->value_delta, $good + $damaged, $line->quantity_packs);
            $usable = Proportion::floor(-(int) $cost->value_delta, $good, $line->quantity_packs);

            return ['value' => $value, 'policy_id' => null, 'bucket_values' => ['usable' => $usable, 'quarantined' => $value - $usable]];
        }
        if (! $this->eligible($order, $line, $good, $damaged)) {
            return $none;
        }
        $invoices = DB::table('invoice_shipment_lines')->join('invoices', 'invoices.id', '=', 'invoice_shipment_lines.invoice_id')->where('invoice_shipment_lines.shipment_line_id', $line->id)->where('invoices.status', 'accepted')->where('invoices.partner_id', $order->organization_id)->where('invoices.organization_id', $order->supplier_organization_id)->get(['invoices.id', 'invoices.total_amount', 'invoices.subtotal', 'invoices.discount_amount', 'invoices.shipping_amount', 'invoices.tax_amount']);
        $events = DB::table('accounting_events')->where('organization_id', $order->organization_id)->where('source_type', 'wholesale_invoice')->whereIn('source_id', $invoices->pluck('id')->all())->get(['source_id', 'policy_id', 'status', 'reason'])->keyBy('source_id');
        foreach ($invoices as $invoice) {
            $event = $events->get($invoice->id);
            $free = (int) $invoice->total_amount === 0 && (int) $invoice->subtotal === (int) $invoice->discount_amount && (int) $invoice->shipping_amount === 0 && (int) $invoice->tax_amount === 0;
            if (! $event && ! $free) {
                return $none;
            }
            if ($event && ($event->status === 'reconciled' || ($event->status === 'review_required' && ! str_starts_with($event->reason ?? '', 'Tanggal sumber')))) {
                return $none;
            }
        }
        $origins = $events->pluck('policy_id')->unique();
        if ($origins->count() > 1) {
            return $none;
        }
        $p = $this->policy($order->organization_id, $origins->first());

        if (! $p) {
            return $none;
        }
        $received = $good + $damaged;
        $value = $received === 0 ? 0 : Rupiah::multiply($unitPrice, $received);

        return ['value' => $value, 'policy_id' => $p->id, 'bucket_values' => ['usable' => $good === 0 ? 0 : Rupiah::multiply($unitPrice, $good), 'quarantined' => $damaged === 0 ? 0 : Rupiah::multiply($unitPrice, $damaged)]];
    }

    public function value(SupplyOrder $order, ShipmentLine $line, int $good, int $damaged, int $unitPrice): ?int
    {
        return $this->quote($order, $line, $good, $damaged, $unitPrice)['value'];
    }

    public function received(SupplyOrder $order, ShipmentLine $line, int $actor, ?int $value, ?string $policyId = null): void
    {
        if ($order->kind === 'internal_transfer' && $order->supplier_organization_id === $order->organization_id) {
            if ($value !== null) {
                $cost = DB::table('inventory_cost_entries')->where('organization_id', $order->organization_id)->where('source_type', 'shipment_dispatch')->where('source_id', $line->id)->first();
                $parts = DB::table('inventory_cost_entries')->where('organization_id', $order->organization_id)->where('source_type', 'shipment_receipt')->where('source_id', $line->id)->where('quantity_delta', '>', 0)->limit(3)->get();
                $received = $line->good_packs + $line->damaged_packs;
                abort_unless($cost && $cost->value_delta !== null && $cost->value_delta <= 0 && -(int) $cost->quantity_delta === $line->quantity_packs * $line->pack_size && $value === Proportion::floor(-(int) $cost->value_delta, $received, $line->quantity_packs) && $parts->count() <= 2 && ! $parts->contains(fn ($p) => $p->value_delta === null) && (int) $parts->sum('quantity_delta') === $received * $line->pack_size && (int) $parts->sum('value_delta') === $value, 409, 'Bukti biaya transfer internal tidak sesuai penerimaan aktual.');
                $sourceEvent = DB::table('accounting_events')->where('organization_id', $order->organization_id)->where('source_type', 'stock_review')->where('source_id', $cost->id)->lockForUpdate()->first();
                $active = DB::table('accounting_policies')->where('organization_id', $order->organization_id)->where('status', 'active')->first();
                $origin = $sourceEvent ? DB::table('accounting_policies')->where('organization_id', $order->organization_id)->where('id', $sourceEvent->policy_id)->first() : null;
                if ($active && $origin && json_decode($active->mapping, true)['finished']['account_id'] === json_decode($origin->mapping, true)['finished']['account_id'] && $sourceEvent->status === 'review_required') {
                    $evidence = 'Transfer internal satu organisasi/akun persediaan; diterima '.$received.'/'.$line->quantity_packs.' kemasan, baik '.$line->good_packs.', karantina '.$line->damaged_packs.'; biaya aktual Rp'.$value.'; sumber '.$line->id;
                    $this->covered($order->organization_id, 'shipment_receipt', $line->id, $evidence);
                    $residual = -(int) $cost->value_delta - $value;
                    if ($received === $line->quantity_packs || $residual === 0) {
                        $this->covered($order->organization_id, 'shipment_dispatch', $line->id, $evidence);
                    } else {
                        DB::table('accounting_events')->where('id', $sourceEvent->id)->where('status', 'review_required')->update(['reason' => $evidence.'; belum diterima '.($line->quantity_packs - $received).' kemasan; sisa nilai Rp'.$residual.' memerlukan penyelesaian berizin, bukan HPP penjualan atau stok diterima fiktif.', 'version' => $sourceEvent->version + 1, 'updated_at' => now()]);
                    }
                }
            }

            return; // One entity, same inventory account: no revenue, payable or COGS journal.
        }
        if (! $this->eligible($order, $line, $line->good_packs, $line->damaged_packs)) {
            return;
        }
        $day = now('Asia/Jakarta')->format('Y-m-d');
        if ($value !== null) {
            $buyer = $this->policy($order->organization_id, $policyId);
            abort_unless($buyer, 409, 'Konfigurasi penerimaan berubah; tinjau ulang.');
            $parts = DB::table('inventory_cost_entries')->where('organization_id', $order->organization_id)->where('source_type', 'shipment_receipt')->where('source_id', $line->id)->where('quantity_delta', '>', 0)->limit(3)->get();
            abort_unless($parts->count() <= 2 && ! $parts->contains(fn ($p) => $p->value_delta === null) && (int) $parts->sum('quantity_delta') === ($line->good_packs + $line->damaged_packs) * $line->pack_size && (int) $parts->sum('value_delta') === $value, 409, 'Bukti nilai penerimaan tidak sesuai ledger aktual.');
            DB::table('accounting_receipt_costs')->insert(['id' => (string) Str::ulid(), 'organization_id' => $order->organization_id, 'shipment_line_id' => $line->id, 'policy_id' => $buyer->id, 'actor_id' => $actor, 'received_on' => $day, 'good_pieces' => $line->good_packs * $line->pack_size, 'quarantined_pieces' => $line->damaged_packs * $line->pack_size, 'missing_pieces' => ($line->quantity_packs - $line->good_packs - $line->damaged_packs) * $line->pack_size, 'quoted_value' => $value, 'created_at' => now()]);
            app(AutomaticPosting::class)->record($order->organization_id, $actor, 'distribution_receipt', $line->id, $day, [['finished', 'purchase_clearing', $value]], 'Pesanan disetujui '.$order->id, null, $buyer->id);
            $this->covered($order->organization_id, 'shipment_receipt', $line->id);
            app(InvoiceValuation::class)->captureReceived($order->organization_id, $line->id, $actor);
        }
        if ($seller = $this->policy($order->supplier_organization_id)) {
            $cost = DB::table('inventory_cost_entries')->where('organization_id', $order->supplier_organization_id)->where('source_type', 'shipment_dispatch')->where('source_id', $line->id)->first();
            $known = $cost && $cost->value_delta !== null && -(int) $cost->quantity_delta === $line->quantity_packs * $line->pack_size;
            $received = $line->good_packs + $line->damaged_packs;
            $recognized = $known ? Proportion::floor(-(int) $cost->value_delta, $received, $line->quantity_packs) : null;
            $origin = $cost ? DB::table('accounting_events')->where('organization_id', $order->supplier_organization_id)->where('source_type', 'stock_review')->where('source_id', $cost->id)->lockForUpdate()->first() : null;
            $reason = null;
            if (! $known || -(int) $cost->value_delta > 0) {
                if (! $origin) {
                    $reason = 'Dispatch asal sebelum aktivasi atau tidak mempunyai bukti buku; rekonsiliasi cutover diperlukan.';
                } elseif ($origin->status !== 'review_required') {
                    $reason = 'Dispatch asal sudah direkonsiliasi; jangan menggandakan HPP tanpa pemeriksaan sumber.';
                } else {
                    $original = DB::table('accounting_policies')->where('organization_id', $order->supplier_organization_id)->where('id', $origin->policy_id)->first();
                    if (! $original || json_decode($original->mapping, true)['finished']['account_id'] !== json_decode($seller->mapping, true)['finished']['account_id']) {
                        $reason = 'Akun persediaan sumber berubah; rekonsiliasi pemetaan diperlukan.';
                    }
                }
            }
            if ($received > 0 || $reason) {
                app(AutomaticPosting::class)->record($order->supplier_organization_id, $actor, 'distribution_cogs', $line->id, $day, [['cogs', 'finished', $recognized]], 'Penerimaan '.$received.'/'.$line->quantity_packs.' kemasan dikonfirmasi '.$order->id, $reason, $seller->id);
            }
            if ($known && ! $reason && ($received === $line->quantity_packs || (int) $cost->value_delta === 0)) {
                $this->covered($order->supplier_organization_id, 'shipment_dispatch', $line->id);
            } elseif ($known && ! $reason && $origin && $received < $line->quantity_packs) {
                DB::table('accounting_events')->where('id', $origin->id)->where('status', 'review_required')->update(['reason' => 'Selisih fisik '.($line->quantity_packs - $received).' kemasan: HPP penerimaan Rp'.$recognized.' dari biaya dispatch Rp'.(-(int) $cost->value_delta).'; sisa nilai Rp'.(-(int) $cost->value_delta - $recognized).' memerlukan penyelesaian berizin, bukan barang diterima fiktif.', 'version' => $origin->version + 1, 'updated_at' => now()]);
            }
            if ($known && ! $reason && $received < $line->quantity_packs) {
                app(DistributionCosting::class)->missing($order, $line->shipment_id, $cost, $received * $line->pack_size, $actor);
            }
        }
    }

    private function covered(string $org, string $type, string $source, ?string $reason = null): void
    {
        $ids = DB::table('inventory_cost_entries')->where('organization_id', $org)->where('source_type', $type)->where('source_id', $source)->pluck('id');
        DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'stock_review')->whereIn('source_id', $ids)->where('status', 'review_required')->update(['status' => 'reconciled', 'reason' => $reason ?? 'Dicakup aturan penerimaan dan event distribusi '.$source, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }
}
