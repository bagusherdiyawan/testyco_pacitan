<?php

namespace App\Modules\Billing\Application;

use App\Models\User;
use App\Modules\Accounting\Application\BillingPosting;
use App\Modules\Billing\Infrastructure\ConsignmentReport;
use App\Modules\Billing\Infrastructure\Invoice;
use App\Modules\Billing\Infrastructure\InvoiceCredit;
use App\Modules\Billing\Infrastructure\InvoicePayment;
use App\Modules\Fulfillment\Infrastructure\ReturnLine;
use App\Modules\Fulfillment\Infrastructure\ReturnShipment;
use App\Modules\Fulfillment\Infrastructure\Shipment;
use App\Modules\Fulfillment\Infrastructure\ShipmentDiscrepancy;
use App\Modules\Fulfillment\Infrastructure\ShipmentDiscrepancyResolution;
use App\Modules\Fulfillment\Infrastructure\ShipmentLine;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Ordering\Application\OrderAccess;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Modules\Partnership\Infrastructure\PartnershipContract;
use App\Shared\Application\Operations;
use App\Shared\Domain\Rupiah;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BillingWorkflow
{
    public function __construct(private Access $access, private OrderAccess $orders, private Operations $ops) {}

    private function authorize(User $u, SupplyOrder $o, ?string $party = null): string
    {
        abort_unless($o->kind === 'wholesale' && $o->approved_at, 403, 'Hanya pesanan grosir disetujui.');
        $source = in_array($this->access->role($u, $o->supplier_organization_id)?->value, ['owner', 'admin'], true);
        $target = $this->access->role($u, $o->organization_id)?->value === 'partner_lead';
        abort_unless($party === 'source' ? $source : ($party === 'target' ? $target : ($source || $target)), 403);

        return $party ?? ($target ? 'target' : 'source');
    }

    private function audit(User $u, SupplyOrder $o, string $action, string $id, array $d): void
    {
        foreach (array_unique([$o->supplier_organization_id, $o->organization_id]) as $org) {
            Audit::record($u->id, $org, 'billing.'.$action, $id, null, $d);
        }
    }

    private function run(User $u, string $order, array $v, string $scope, ?string $party, callable $work): array
    {
        $o = SupplyOrder::findOrFail($order);
        $this->authorize($u, $o, $party);

        return $this->ops->run($o->supplier_organization_id, $u->id, $v['operation_id'], 'billing.'.$scope.':'.$order, $v, function () use ($u, $order, $party, $work) {
            $o = SupplyOrder::lockForUpdate()->findOrFail($order);
            $this->authorize($u, $o, $party);
            $this->orders->targetActive($o);

            return $work($o);
        });
    }

    public function balance(Invoice $i): array
    {
        $credits = InvoiceCredit::where('invoice_id', $i->id)->where('status', 'accepted')->get();
        $credit = (int) $credits->sum(fn ($c) => $c->base_amount + $c->tax_amount);
        $payments = InvoicePayment::where('invoice_id', $i->id)->where('status', 'verified')->get();
        $collected = (int) $payments->where('kind', 'collection')->sum('amount');
        $refunded = (int) $payments->where('kind', 'credit_refund')->sum('amount');
        $balance = $i->total_amount - $credit - $collected + $refunded;

        return ['credit_amount' => $credit, 'collected_amount' => $collected, 'refunded_amount' => $refunded, 'signed_balance' => $balance, 'outstanding_amount' => max(0, $balance), 'customer_credit_amount' => max(0, -$balance)];
    }

    private function document(Invoice $i): array
    {
        return [...$i->toArray(), ...$this->balance($i), 'payments' => InvoicePayment::where('invoice_id', $i->id)->orderBy('id')->get()->toArray(), 'credits' => InvoiceCredit::where('invoice_id', $i->id)->orderBy('id')->get()->toArray()];
    }

    public function context(User $u, string $order): array
    {
        $o = SupplyOrder::with('lines')->findOrFail($order);
        $party = $this->authorize($u, $o);

        return ['order_id' => $order, 'organization_id' => $o->supplier_organization_id, 'partner_id' => $o->organization_id, 'party' => $party, 'can_create' => in_array($this->access->role($u, $o->supplier_organization_id)?->value, ['owner', 'admin'], true), 'can_confirm' => $this->access->role($u, $o->organization_id)?->value === 'partner_lead', 'order_lines' => $o->lines->toArray(), 'shipments' => Shipment::where('supply_order_id', $order)->with('lines')->orderBy('id')->get()->toArray(), 'cases' => ShipmentDiscrepancy::where('supply_order_id', $order)->orderBy('id')->get()->toArray(), 'invoices' => Invoice::where('supply_order_id', $order)->orderByDesc('id')->paginate(30)->through(fn ($i) => $this->document($i))->toArray(), 'reports' => ConsignmentReport::where('supply_order_id', $order)->orderByDesc('id')->paginate(30, ['*'], 'reports_page')->toArray(), 'contracts' => PartnershipContract::where('organization_id', $o->supplier_organization_id)->where('partner_id', $o->organization_id)->where('status', 'accepted')->where('mode', 'consignment')->orderBy('id')->get(['id', 'title', 'starts_on', 'ends_on'])->toArray()];
    }

    public function invoice(User $u, string $order, array $v): array
    {
        return $this->run($u, $order, $v, 'invoice', 'source', function ($o) use ($u, $v) {
            abort_if(DB::table('commercial_agreements')->where('active_order_id', $o->id)->exists(), 409, 'Pesanan memakai perjanjian otomatis; jangan tagih dua kali.');
            $lines = [];
            $shipmentIds = [];
            $report = null;
            if ($v['mode'] === 'consignment') {
                abort_unless($v['basis'] === 'consignment_report' && isset($v['report_id']), 422, 'Konsinyasi wajib laporan penjualan disetujui.');
                $report = ConsignmentReport::where('supply_order_id', $o->id)->where('status', 'accepted')->lockForUpdate()->findOrFail($v['report_id']);
                abort_if(Invoice::where('active_report_id', $report->id)->exists(), 409, 'Laporan sudah ditagih.');
                $lines = $report->lines;
            } else {
                abort_if(ConsignmentReport::where('supply_order_id', $o->id)->whereIn('status', ['pending', 'accepted'])->exists(), 409, 'Pesanan sudah dalam konsinyasi.');
                abort_unless(in_array($v['basis'], ['dispatched', 'good_received'], true) && ! empty($v['shipment_ids']), 422, 'Pilih dasar dan kiriman.');
                foreach ($v['shipment_ids'] as $id) {
                    $s = Shipment::where('supply_order_id', $o->id)->lockForUpdate()->findOrFail($id);
                    abort_unless($v['basis'] === 'dispatched' || $s->received_at, 409, 'Kiriman belum diterima.');
                    foreach (ShipmentLine::where('shipment_id', $id)->orderBy('id')->get() as $sl) {
                        $ol = $o->lines()->findOrFail($sl->supply_order_line_id);
                        $n = $v['basis'] === 'dispatched' ? $sl->quantity_packs : $sl->good_packs;
                        $shipmentIds[] = $sl->id;
                        abort_if(DB::table('invoice_shipment_lines')->where('active_shipment_line_id', $sl->id)->exists(), 409, 'Kiriman sudah masuk tagihan aktif.');
                        $lines[] = ['shipment_line_id' => $sl->id, 'order_line_id' => $ol->id, 'sku' => $ol->sku, 'name' => $ol->name, 'pack_size' => $sl->pack_size, 'quantity_packs' => $n, 'quantity_pieces' => $n * $sl->pack_size, 'unit_price' => $ol->unit_price, 'line_total' => $n === 0 ? 0 : Rupiah::multiply($ol->unit_price, $n)];
                    }
                }
            }
            $subtotal = 0;
            foreach ($lines as $l) {
                $subtotal = Rupiah::add($subtotal, $l['line_total']);
            }
            abort_unless($subtotal > 0 && (int) $v['discount_amount'] <= $subtotal, 422, 'Diskon melebihi subtotal atau tagihan kosong.');
            $net = Rupiah::add($subtotal - (int) $v['discount_amount'], (int) $v['shipping_amount']);
            $tax = intdiv($net * (int) $v['tax_basis_points'] + 5000, 10000);
            $total = Rupiah::add($net, $tax);
            $i = Invoice::create(['supply_order_id' => $o->id, 'organization_id' => $o->supplier_organization_id, 'partner_id' => $o->organization_id, 'consignment_report_id' => $report?->id, 'active_report_id' => $report?->id, 'number' => 'INV-'.Str::ulid(), 'status' => 'pending', 'version' => 1, 'basis' => $v['basis'], 'mode' => $v['mode'], 'currency' => 'IDR', 'rounding' => $v['rounding'], 'due_on' => $v['due_on'], 'subtotal' => $subtotal, 'discount_amount' => (int) $v['discount_amount'], 'shipping_amount' => (int) $v['shipping_amount'], 'tax_basis_points' => (int) $v['tax_basis_points'], 'tax_amount' => $tax, 'total_amount' => $total, 'lines' => $lines, 'note' => $v['note'], 'created_by' => $u->id]);
            foreach ($shipmentIds as $id) {
                DB::table('invoice_shipment_lines')->insert(['invoice_id' => $i->id, 'shipment_line_id' => $id, 'active_shipment_line_id' => $id]);
            }
            $this->audit($u, $o, 'invoice_proposed', $i->id, $i->toArray());

            return $this->document($i);
        });
    }

    public function decideInvoice(User $u, string $order, string $id, string $action, array $v): array
    {
        return $this->run($u, $order, $v, 'invoice.'.$action.':'.$id, $action === 'withdraw' ? 'source' : 'target', function ($o) use ($u, $id, $action, $v) {
            $i = Invoice::where('supply_order_id', $o->id)->lockForUpdate()->findOrFail($id);
            abort_unless($i->version === (int) $v['version'] && $i->status === 'pending', 409, 'Versi/status tagihan berubah.');
            if ($action === 'accept') {
                abort_unless(($v['consent'] ?? false) === true, 422, 'Konfirmasi eksplisit wajib.');
            }if ($action !== 'accept') {
                $i->active_report_id = null;
            }$i->status = ['accept' => 'accepted', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][$action];
            $i->version++;
            $i->decided_by = $u->id;
            $i->decided_at = now();
            $i->decision_note = $v['reason'];
            $i->save();
            if ($action === 'accept') {
                app(BillingPosting::class)->invoice($i, $u->id);
            }
            if ($action !== 'accept') {
                DB::table('invoice_shipment_lines')->where('invoice_id', $id)->update(['active_shipment_line_id' => null]);
            }$this->audit($u, $o, 'invoice_'.$action, $id, $i->toArray());

            return $this->document($i);
        });
    }

    public function payment(User $u, string $order, string $id, array $v): array
    {
        return $this->run($u, $order, $v, 'payment:'.$id, 'source', function ($o) use ($u, $id, $v) {
            $i = Invoice::where('supply_order_id', $o->id)->lockForUpdate()->findOrFail($id);
            abort_unless($i->status === 'accepted' && $i->version === (int) $v['version'], 409, 'Tagihan/versi belum sah.');
            abort_unless($v['verified'] === true, 422, 'Bukti pembayaran wajib diverifikasi manusia.');
            $b = $this->balance($i);
            $limit = $v['kind'] === 'collection' ? $b['outstanding_amount'] : $b['customer_credit_amount'];
            abort_unless((int) $v['amount'] <= $limit, 422, 'Nominal melebihi saldo yang dapat dialokasikan.');
            abort_if(InvoicePayment::where('invoice_id', $id)->where('reference', $v['reference'])->exists(), 409, 'Referensi pembayaran sudah digunakan.');
            $p = InvoicePayment::create(['invoice_id' => $id, 'kind' => $v['kind'], 'method' => $v['method'], 'amount' => (int) $v['amount'], 'reference' => $v['reference'], 'paid_on' => $v['paid_on'], 'note' => $v['note'], 'status' => 'verified', 'verified_by' => $u->id, 'verified_at' => now()]);
            $i->increment('version');
            app(BillingPosting::class)->payment($i, $p, $u->id);
            $this->audit($u, $o, 'payment_verified', $p->id, $p->toArray());

            return $this->document($i->refresh());
        });
    }

    public function reversePayment(User $u, string $order, string $id, string $payment, array $v): array
    {
        return $this->run($u, $order, $v, 'payment.reverse:'.$payment, 'source', function ($o) use ($u, $id, $payment, $v) {
            $i = Invoice::where('supply_order_id', $o->id)->lockForUpdate()->findOrFail($id);
            abort_unless($i->status === 'accepted' && $i->version === (int) $v['version'], 409);
            $p = InvoicePayment::where('invoice_id', $id)->lockForUpdate()->findOrFail($payment);
            abort_unless($p->status === 'verified', 409);
            if ($p->kind === 'collection') {
                abort_unless($this->balance($i)['refunded_amount'] === 0, 409, 'Rekonsiliasi refund kredit sebelum membalik penerimaan.');
            }$p->fill(['status' => 'reversed', 'reversed_by' => $u->id, 'reversed_at' => now(), 'reversal_reason' => $v['reason']])->save();
            $i->increment('version');
            app(BillingPosting::class)->payment($i, $p, $u->id, true);
            $this->audit($u, $o, 'payment_reversed', $p->id, $p->toArray());

            return $this->document($i->refresh());
        });
    }

    private function creditLimit(SupplyOrder $o, Invoice $i, string $case): array
    {
        $c = ShipmentDiscrepancy::where('supply_order_id', $o->id)->lockForUpdate()->findOrFail($case);
        $resolution = ShipmentDiscrepancyResolution::where('shipment_discrepancy_id', $c->id)->where('status', 'confirmed')->lockForUpdate()->firstOrFail();
        abort_unless(in_array($resolution->type, ['return', 'credit'], true) && $i->mode === 'buyout', 409, 'Kredit hanya rencana retur/kredit disepakati untuk putus beli.');
        $cap = 0;
        foreach ($c->snapshot['lines'] as $line) {
            $il = collect($i->lines)->firstWhere('shipment_line_id', $line['shipment_line_id']);
            if (! $il) {
                continue;
            }$n = 0;
            if ($resolution->type === 'credit' && $i->basis === 'dispatched') {
                $n = $line['damaged_packs'] + $line['missing_packs'];
            }
            if ($resolution->type === 'return' && $i->basis === 'dispatched') {
                $n = (int) ReturnLine::whereIn('return_shipment_id', ReturnShipment::where('case_id', $case)->whereNotNull('received_at')->select('id'))->where('original_shipment_line_id', $line['shipment_line_id'])->sum('received_packs');
            }
            $cap = Rupiah::add($cap, $n === 0 ? 0 : Rupiah::multiply($il['unit_price'], $n));
        }
        $used = (int) InvoiceCredit::where('invoice_id', $i->id)->where('case_id', $case)->where('status', 'accepted')->sum('base_amount');
        $all = InvoiceCredit::where('invoice_id', $i->id)->where('status', 'accepted')->get();

        return ['resolution_id' => $resolution->id, 'base' => max(0, min($cap - $used, $i->subtotal - $i->discount_amount - (int) $all->sum('base_amount'))), 'tax' => max(0, $i->tax_amount - (int) $all->sum('tax_amount'))];
    }

    public function credit(User $u, string $order, string $id, array $v): array
    {
        return $this->run($u, $order, $v, 'credit:'.$id, 'source', function ($o) use ($u, $id, $v) {
            // Parent order serializes finance with case and physical-return mutations.
            $i = Invoice::where('supply_order_id', $o->id)->lockForUpdate()->findOrFail($id);
            abort_unless($i->status === 'accepted' && $i->version === (int) $v['version'], 409);
            abort_if(InvoiceCredit::where('pending_invoice_id', $id)->exists(), 409, 'Selesaikan nota kredit yang menunggu.');
            $limit = $this->creditLimit($o, $i, $v['case_id']);
            abort_unless((int) $v['base_amount'] <= $limit['base'] && (int) $v['tax_amount'] <= min($limit['tax'], intdiv((int) $v['base_amount'] * $i->tax_basis_points + 5000, 10000)), 422, 'Kredit melebihi dasar historis/retur aktual atau pajak.');
            $c = InvoiceCredit::create(['invoice_id' => $id, 'case_id' => $v['case_id'], 'resolution_id' => $limit['resolution_id'], 'pending_invoice_id' => $id, 'status' => 'pending', 'version' => 1, 'base_amount' => (int) $v['base_amount'], 'tax_amount' => (int) $v['tax_amount'], 'reason' => $v['reason'], 'created_by' => $u->id]);
            $i->increment('version');
            $this->audit($u, $o, 'credit_proposed', $c->id, $c->toArray());

            return $this->document($i->refresh());
        });
    }

    public function decideCredit(User $u, string $order, string $id, string $credit, string $action, array $v): array
    {
        return $this->run($u, $order, $v, 'credit.'.$action.':'.$credit, $action === 'withdraw' ? 'source' : 'target', function ($o) use ($u, $id, $credit, $action, $v) {
            $i = Invoice::where('supply_order_id', $o->id)->lockForUpdate()->findOrFail($id);
            abort_unless($i->version === (int) $v['version'], 409);
            $c = InvoiceCredit::where('invoice_id', $id)->lockForUpdate()->findOrFail($credit);
            abort_unless($c->status === 'pending', 409);
            if ($action === 'accept') {
                $limit = $this->creditLimit($o, $i, $c->case_id);
                abort_unless(($v['consent'] ?? false) === true && $c->base_amount <= $limit['base'] && $c->tax_amount <= $limit['tax'], 422, 'Periksa persetujuan dan batas kredit.');
            }$c->fill(['status' => ['accept' => 'accepted', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][$action], 'pending_invoice_id' => null, 'version' => $c->version + 1, 'decided_by' => $u->id, 'decided_at' => now(), 'decision_note' => $v['reason']])->save();
            $i->increment('version');
            if ($action === 'accept') {
                app(BillingPosting::class)->credit($i, $c, $u->id);
            }
            $this->audit($u, $o, 'credit_'.$action, $credit, $c->toArray());

            return $this->document($i->refresh());
        });
    }

    public function report(User $u, string $order, array $v): array
    {
        return $this->run($u, $order, $v, 'consignment', 'target', function ($o) use ($u, $v) {
            abort_if(DB::table('accounting_events')->whereIn('source_type', ['distribution_receipt', 'distribution_cogs'])->whereIn('source_id', DB::table('shipment_lines')->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')->where('shipments.supply_order_id', $o->id)->select('shipment_lines.id'))->exists(), 409, 'Penerimaan putus beli sudah dibukukan; rekonsiliasi dahulu sebelum mengganti menjadi konsinyasi.');
            abort_if(DB::table('commercial_agreements')->where('active_order_id', $o->id)->exists(), 409, 'Pesanan memakai perjanjian otomatis.');
            abort_if(Invoice::where('supply_order_id', $o->id)->where('mode', 'buyout')->whereIn('status', ['pending', 'accepted'])->exists(), 409, 'Pesanan sudah dalam putus beli.');
            $contract = PartnershipContract::where('organization_id', $o->supplier_organization_id)->where('partner_id', $o->organization_id)->where('status', 'accepted')->where('mode', 'consignment')->lockForUpdate()->findOrFail($v['contract_id']);
            abort_unless($v['period_start'] >= $contract->starts_on->format('Y-m-d') && $v['period_end'] <= $contract->ends_on->format('Y-m-d'), 422, 'Periode di luar kontrak.');
            $lines = [];
            foreach ($v['lines'] as $row) {
                $ol = $o->lines()->findOrFail($row['order_line_id']);
                $received = (int) ShipmentLine::whereIn('shipment_id', Shipment::where('supply_order_id', $o->id)->whereNotNull('received_at')->select('id'))->where('supply_order_line_id', $ol->id)->sum('good_packs') * $ol->pack_size;
                $used = 0;
                foreach (ConsignmentReport::where('supply_order_id', $o->id)->whereIn('status', ['pending', 'accepted'])->get() as $report) {
                    $used += (int) collect($report->lines)->where('order_line_id', $ol->id)->sum('quantity_pieces');
                }abort_unless((int) $row['quantity_pieces'] <= $received - $used, 422, 'Penjualan konsinyasi melebihi penerimaan baik yang belum dilaporkan.');
                $lines[] = ['order_line_id' => $ol->id, 'sku' => $ol->sku, 'name' => $ol->name, 'pack_size' => $ol->pack_size, 'unit_price' => $ol->unit_price, 'quantity_pieces' => (int) $row['quantity_pieces'], 'line_total' => intdiv(Rupiah::multiply($ol->unit_price, (int) $row['quantity_pieces']) + intdiv($ol->pack_size, 2), $ol->pack_size)];
            }
            $c = ConsignmentReport::create(['supply_order_id' => $o->id, 'contract_id' => $contract->id, 'status' => 'pending', 'version' => 1, 'lines' => $lines, 'period_start' => $v['period_start'], 'period_end' => $v['period_end'], 'note' => $v['note'], 'created_by' => $u->id]);
            $this->audit($u, $o, 'consignment_reported', $c->id, $c->toArray());

            return $c->toArray();
        });
    }

    public function decideReport(User $u, string $order, string $id, string $action, array $v): array
    {
        return $this->run($u, $order, $v, 'consignment.'.$action.':'.$id, $action === 'withdraw' ? 'target' : 'source', function ($o) use ($u, $id, $action, $v) {
            $r = ConsignmentReport::where('supply_order_id', $o->id)->lockForUpdate()->findOrFail($id);
            abort_unless($r->status === 'pending' && $r->version === (int) $v['version'], 409);
            if ($action === 'accept') {
                abort_unless(($v['consent'] ?? false) === true, 422);
            }$r->fill(['status' => ['accept' => 'accepted', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][$action], 'version' => $r->version + 1, 'decided_by' => $u->id, 'decided_at' => now(), 'decision_note' => $v['reason']])->save();
            $this->audit($u, $o, 'consignment_'.$action, $id, $r->toArray());

            return $r->toArray();
        });
    }
}
