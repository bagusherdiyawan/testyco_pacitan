<?php

namespace App\Modules\Accounting\Application;

use App\Modules\Fulfillment\Infrastructure\ShipmentLine;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Shared\Domain\Rupiah;
use Illuminate\Support\Facades\DB;

final class DistributionReceiptPosting
{
    private function policy(string $org): ?object
    {
        return DB::table('accounting_policies as p')->join('accounting_wholesale_policies as w', 'w.policy_id', '=', 'p.id')->where('p.organization_id', $org)->where('w.organization_id', $org)->where('p.status', 'active')->where('w.ownership_basis', 'receipt_confirmed')->where('w.provisional_basis', 'approved_order')->select('p.id')->first();
    }

    public function eligible(SupplyOrder $order, ShipmentLine $line, int $good, int $damaged): bool
    {
        if ($order->kind !== 'wholesale' || $good !== $line->quantity_packs || $damaged !== 0) {
            return false;
        }
        if (DB::table('commercial_agreements')->where('active_order_id', $order->id)->exists() || DB::table('consignment_reports')->where('supply_order_id', $order->id)->whereIn('status', ['pending', 'accepted'])->exists()) {
            return false;
        }
        $buyout = DB::table('invoices')->where('supply_order_id', $order->id)->where('mode', 'buyout')->where('status', 'accepted')->exists();

        return $buyout || ! DB::table('partnership_contracts')->where('organization_id', $order->supplier_organization_id)->where('partner_id', $order->organization_id)->where('mode', 'consignment')->where('status', 'accepted')->exists();
    }

    public function value(SupplyOrder $order, ShipmentLine $line, int $good, int $damaged, int $unitPrice): ?int
    {
        $p = $this->policy($order->organization_id);
        if (! $p || ! $this->eligible($order, $line, $good, $damaged)) {
            return null;
        }
        $invoices = DB::table('invoice_shipment_lines')->join('invoices', 'invoices.id', '=', 'invoice_shipment_lines.invoice_id')->where('invoice_shipment_lines.shipment_line_id', $line->id)->where('invoices.status', 'accepted')->select('invoices.id');
        if (DB::table('accounting_events')->where('organization_id', $order->organization_id)->where('source_type', 'wholesale_invoice')->whereIn('source_id', $invoices)->where('policy_id', '!=', $p->id)->exists()) {
            return null;
        }

        return Rupiah::multiply($unitPrice, $good);
    }

    public function received(SupplyOrder $order, ShipmentLine $line, int $actor, ?int $value): void
    {
        if (! $this->eligible($order, $line, $line->good_packs, $line->damaged_packs)) {
            return;
        }
        $day = now('Asia/Jakarta')->format('Y-m-d');
        if ($value !== null && ($buyer = $this->policy($order->organization_id))) {
            app(AutomaticPosting::class)->record($order->organization_id, $actor, 'distribution_receipt', $line->id, $day, [['finished', 'purchase_clearing', $value]], 'Pesanan disetujui '.$order->id, null, $buyer->id);
            $this->covered($order->organization_id, 'shipment_receipt', $line->id);
        }
        if ($seller = $this->policy($order->supplier_organization_id)) {
            $cost = DB::table('inventory_cost_entries')->where('organization_id', $order->supplier_organization_id)->where('source_type', 'shipment_dispatch')->where('source_id', $line->id)->first();
            $known = $cost && $cost->value_delta !== null && -(int) $cost->quantity_delta === $line->quantity_packs * $line->pack_size;
            app(AutomaticPosting::class)->record($order->supplier_organization_id, $actor, 'distribution_cogs', $line->id, $day, [['cogs', 'finished', $known ? -(int) $cost->value_delta : null]], 'Penerimaan dikonfirmasi '.$order->id, null, $seller->id);
            if ($known) {
                $this->covered($order->supplier_organization_id, 'shipment_dispatch', $line->id);
            }
        }
    }

    private function covered(string $org, string $type, string $source): void
    {
        $ids = DB::table('inventory_cost_entries')->where('organization_id', $org)->where('source_type', $type)->where('source_id', $source)->pluck('id');
        DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'stock_review')->whereIn('source_id', $ids)->where('status', 'review_required')->update(['status' => 'reconciled', 'reason' => 'Dicakup aturan penerimaan dan event distribusi '.$source, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }
}
