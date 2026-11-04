<?php

namespace App\Modules\Billing\Presentation;

use App\Modules\Billing\Application\BillingWorkflow;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BillingController
{
    public function __construct(private BillingWorkflow $workflow) {}

    private function run(callable $f, int $status = 200): mixed
    {
        try {
            return response()->json(['data' => $f()], $status)->header('Cache-Control', 'private, no-store');
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
    }

    private function key(): array
    {
        return ['operation_id' => 'required|uuid'];
    }

    private function decision(): array
    {
        return [...$this->key(), 'version' => 'required|integer|min:1', 'consent' => 'sometimes|boolean', 'reason' => 'required|string|max:2000'];
    }

    public function show(Request $r, string $order): mixed
    {
        $r->validate(['page' => 'sometimes|integer|min:1', 'reports_page' => 'sometimes|integer|min:1']);

        return response()->json($this->workflow->context($r->user(), $order))->header('Cache-Control', 'private, no-store');
    }

    public function invoice(Request $r, string $order): mixed
    {
        $v = $r->validate([...$this->key(), 'mode' => 'required|in:buyout,consignment', 'basis' => 'required|in:dispatched,good_received,consignment_report', 'shipment_ids' => 'sometimes|array|max:100', 'shipment_ids.*' => 'required|ulid|distinct', 'report_id' => 'nullable|ulid', 'due_on' => 'required|date_format:Y-m-d', 'discount_amount' => 'required|integer|min:0|max:1000000000000', 'shipping_amount' => 'required|integer|min:0|max:1000000000000', 'tax_basis_points' => 'required|integer|min:0|max:10000', 'rounding' => 'required|in:half_up', 'note' => 'required|string|max:2000']);

        return $this->run(fn () => $this->workflow->invoice($r->user(), $order, $v), 201);
    }

    public function decideInvoice(Request $r, string $order, string $invoice, string $action): mixed
    {
        $v = $r->validate($this->decision());

        return $this->run(fn () => $this->workflow->decideInvoice($r->user(), $order, $invoice, $action, $v));
    }

    public function payment(Request $r, string $order, string $invoice): mixed
    {
        $v = $r->validate([...$this->key(), 'version' => 'required|integer|min:1', 'kind' => 'required|in:collection,credit_refund', 'method' => 'required|in:cash,bank_transfer,qris_manual', 'amount' => 'required|integer|min:1|max:1000000000000', 'reference' => 'required|string|max:160', 'paid_on' => 'required|date_format:Y-m-d', 'verified' => 'required|boolean', 'note' => 'required|string|max:2000']);

        return $this->run(fn () => $this->workflow->payment($r->user(), $order, $invoice, $v), 201);
    }

    public function reverse(Request $r, string $order, string $invoice, string $payment): mixed
    {
        $v = $r->validate($this->decision());

        return $this->run(fn () => $this->workflow->reversePayment($r->user(), $order, $invoice, $payment, $v));
    }

    public function credit(Request $r, string $order, string $invoice): mixed
    {
        $v = $r->validate([...$this->key(), 'version' => 'required|integer|min:1', 'case_id' => 'required|ulid', 'base_amount' => 'required|integer|min:1|max:1000000000000', 'tax_amount' => 'required|integer|min:0|max:1000000000000', 'reason' => 'required|string|max:2000']);

        return $this->run(fn () => $this->workflow->credit($r->user(), $order, $invoice, $v), 201);
    }

    public function decideCredit(Request $r, string $order, string $invoice, string $credit, string $action): mixed
    {
        $v = $r->validate($this->decision());

        return $this->run(fn () => $this->workflow->decideCredit($r->user(), $order, $invoice, $credit, $action, $v));
    }

    public function report(Request $r, string $order): mixed
    {
        $v = $r->validate([...$this->key(), 'contract_id' => 'required|ulid', 'period_start' => 'required|date_format:Y-m-d', 'period_end' => 'required|date_format:Y-m-d|after_or_equal:period_start', 'note' => 'required|string|max:2000', 'lines' => 'required|array|min:1|max:100', 'lines.*.order_line_id' => 'required|ulid|distinct', 'lines.*.quantity_pieces' => 'required|integer|min:1|max:1000000']);

        return $this->run(fn () => $this->workflow->report($r->user(), $order, $v), 201);
    }

    public function decideReport(Request $r, string $order, string $report, string $action): mixed
    {
        $v = $r->validate($this->decision());

        return $this->run(fn () => $this->workflow->decideReport($r->user(), $order, $report, $action, $v));
    }
}
