<?php

namespace App\Modules\Billing\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class BillingController
{
    public function __construct(private Backend $api, private PendingMutation $pending) {}

    private function path(string $order): string
    {
        return 'orders/'.$order.'/billing';
    }

    private function slot(string $order): string
    {
        return 'billing_pending.'.$order;
    }

    private function context(string $order, array $query = []): array
    {
        $c = $this->api->request('GET', $this->path($order), $query)->json();
        abort_unless(is_array($c) && ($c['order_id'] ?? null) === $order && is_string($c['organization_id'] ?? null) && is_string($c['partner_id'] ?? null) && is_bool($c['can_create'] ?? null) && is_bool($c['can_confirm'] ?? null) && is_array($c['shipments'] ?? null) && is_array($c['order_lines'] ?? null) && is_array($c['contracts'] ?? null) && is_array($c['cases'] ?? null), 503, 'Cakupan billing tidak valid.');
        foreach (['invoices' => 'page', 'reports' => 'reports_page'] as $key => $param) {
            $p = $c[$key] ?? null;
            abort_unless(is_array($p) && is_array($p['data'] ?? null) && ($p['current_page'] ?? null) === (int) ($query[$param] ?? 1) && ($p['per_page'] ?? null) === 30 && is_int($p['total'] ?? null) && $p['total'] >= count($p['data']) && count($p['data']) <= 30, 503);
            foreach ($p['data'] as $row) {
                abort_unless(($row['supply_order_id'] ?? null) === $order && is_string($row['id'] ?? null) && is_int($row['version'] ?? null) && is_array($row['lines'] ?? null), 503);
                if ($key === 'invoices') {
                    abort_unless(($row['organization_id'] ?? null) === $c['organization_id'] && ($row['partner_id'] ?? null) === $c['partner_id'] && is_array($row['payments'] ?? null) && is_array($row['credits'] ?? null), 503);
                    foreach ([...$row['payments'], ...$row['credits']] as $child) {
                        abort_unless(($child['invoice_id'] ?? null) === $row['id'], 503);
                    }
                }
            }
        }
        foreach ($c['shipments'] as $s) {
            abort_unless(($s['supply_order_id'] ?? null) === $order && is_array($s['lines'] ?? null), 503);
        }foreach ($c['cases'] as $case) {
            abort_unless(($case['supply_order_id'] ?? null) === $order, 503);
        }foreach ($c['order_lines'] as $l) {
            abort_unless(($l['supply_order_id'] ?? null) === $order, 503);
        }

        return $c;
    }

    public function show(Request $r, string $order): mixed
    {
        $pending = session($this->slot($order));
        $c = null;
        $invoices = $reports = null;
        if (! $pending) {
            $v = $r->validate(['page' => 'sometimes|integer|min:1', 'reports_page' => 'sometimes|integer|min:1']);
            $c = $this->context($order, $v);
            foreach (['invoices', 'reports'] as $key) {
                $p = $c[$key];
                $$key = new LengthAwarePaginator($p['data'], $p['total'], 30, $p['current_page'], ['path' => url()->current(), 'pageName' => $key === 'reports' ? 'reports_page' : 'page', 'query' => $r->only('page', 'reports_page')]);
            }
        }

        return response()->view('pages.billing', compact('order', 'pending', 'c', 'invoices', 'reports'))->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $r, string $order): mixed
    {
        abort_if(session()->has($this->slot($order)), 409, 'Selesaikan request belum pasti.');
        $head = $r->validate(['action' => 'required|in:invoice,invoice_accept,invoice_reject,invoice_withdraw,payment,reverse,credit,credit_accept,credit_reject,credit_withdraw,report,report_accept,report_reject,report_withdraw', 'operation_id' => 'required|uuid', 'invoice_id' => 'nullable|ulid', 'credit_id' => 'nullable|ulid', 'payment_id' => 'nullable|ulid', 'report_id' => 'nullable|ulid']);
        $a = $head['action'];
        $base = ['operation_id' => 'required|uuid'];
        $decision = ['version' => 'required|integer|min:1', 'consent' => 'sometimes|accepted', 'reason' => 'required|string|max:2000'];
        $rules = match ($a) {
            'invoice' => ['mode' => 'required|in:buyout,consignment', 'basis' => 'required|in:dispatched,good_received,consignment_report', 'shipment_ids' => 'sometimes|array|max:100', 'shipment_ids.*' => 'required|ulid|distinct', 'report_id' => 'nullable|ulid', 'due_on' => 'required|date_format:Y-m-d', 'discount_amount' => 'required|integer|min:0|max:1000000000000', 'shipping_amount' => 'required|integer|min:0|max:1000000000000', 'tax_basis_points' => 'required|integer|min:0|max:10000', 'rounding' => 'required|in:half_up', 'note' => 'required|string|max:2000'],
            'payment' => ['version' => 'required|integer|min:1', 'kind' => 'required|in:collection,credit_refund', 'method' => 'required|in:cash,bank_transfer,qris_manual', 'amount' => 'required|integer|min:1|max:1000000000000', 'reference' => 'required|string|max:160', 'paid_on' => 'required|date_format:Y-m-d', 'verified' => 'required|accepted', 'note' => 'required|string|max:2000'],
            'credit' => ['version' => 'required|integer|min:1', 'case_id' => 'required|ulid', 'base_amount' => 'required|integer|min:1|max:1000000000000', 'tax_amount' => 'required|integer|min:0|max:1000000000000', 'reason' => 'required|string|max:2000'],
            'report' => ['contract_id' => 'required|ulid', 'period_start' => 'required|date_format:Y-m-d', 'period_end' => 'required|date_format:Y-m-d|after_or_equal:period_start', 'note' => 'required|string|max:2000', 'lines' => 'required|array|min:1|max:100', 'lines.*.order_line_id' => 'required|ulid|distinct', 'lines.*.quantity_pieces' => 'nullable|integer|min:0|max:1000000'],default => $decision
        };
        $v = $r->validate([...$base, ...$rules]);
        foreach (['version', 'discount_amount', 'shipping_amount', 'tax_basis_points', 'amount', 'base_amount', 'tax_amount'] as $key) {
            if (isset($v[$key])) {
                $v[$key] = (int) $v[$key];
            }
        }foreach (['consent', 'verified'] as $key) {
            if (isset($v[$key])) {
                $v[$key] = true;
            }
        }
        if ($a === 'report') {
            $v['lines'] = array_values(array_map(fn ($l) => [...$l, 'quantity_pieces' => (int) $l['quantity_pieces']], array_filter($v['lines'], fn ($l) => (int) ($l['quantity_pieces'] ?? 0) > 0)));
            abort_unless(count($v['lines']) > 0, 422, 'Isi penjualan aktual.');
        }
        $c = $this->context($order);
        $inv = $head['invoice_id'] ?? null;
        $report = $head['report_id'] ?? null;
        $path = $this->path($order);
        $subject = null;
        $child = null;
        if ($a === 'invoice') {
            $path .= '/invoices';
            abort_unless($c['can_create'], 403);
        } elseif ($a === 'report') {
            $path .= '/reports';
            abort_unless($c['can_confirm'], 403);
        } elseif (str_starts_with($a, 'report_')) {
            $subject = $report;
            abort_unless($subject, 422);
            $path .= '/reports/'.$subject.'/'.substr($a, 7);
        } else {
            abort_unless($inv, 422);
            $subject = $inv;
            $path .= '/invoices/'.$inv;
            if (str_starts_with($a, 'invoice_')) {
                $path .= '/'.substr($a, 8);
            } elseif ($a === 'payment') {
                $path .= '/payments';
            } elseif ($a === 'reverse') {
                $child = $head['payment_id'] ?? null;
                abort_unless($child, 422);
                $path .= '/payments/'.$child.'/reverse';
            } elseif ($a === 'credit') {
                $path .= '/credits';
            } else {
                $child = $head['credit_id'] ?? null;
                abort_unless($child, 422);
                $path .= '/credits/'.$child.'/'.substr($a, 7);
            }
        }
        $p = ['path' => $path, 'body' => $v, 'action' => $a, 'subject' => $subject, 'child' => $child, 'review' => $c, 'order' => $order, 'target' => '/app/'.$this->path($order), 'uncertain' => false];

        return $this->send($order, $p);
    }

    public function retry(string $order): mixed
    {
        $p = session($this->slot($order));
        abort_unless(is_array($p) && ($p['order'] ?? null) === $order, 409);

        return $this->send($order, $p);
    }

    private function send(string $order, array $p): mixed
    {
        return $this->pending->send($this->slot($order), $p, function ($d, $p) use ($order) {
            $a = $p['action'];
            $v = $p['body'];
            if (($d['supply_order_id'] ?? null) !== $order || ! is_string($d['id'] ?? null) || ! is_array($d['lines'] ?? null)) {
                return false;
            }
            if ($a === 'report' || str_starts_with($a, 'report_')) {
                if ($a === 'report') {
                    return ($d['version'] ?? null) === 1 && ($d['status'] ?? null) === 'pending' && ($d['contract_id'] ?? null) === $v['contract_id'] && ($d['period_start'] ?? null) === $v['period_start'] && ($d['period_end'] ?? null) === $v['period_end'] && collect($d['lines'])->map(fn ($l) => [$l['order_line_id'], $l['quantity_pieces']])->all() === collect($v['lines'])->map(fn ($l) => [$l['order_line_id'], $l['quantity_pieces']])->all();
                }

                return $d['id'] === $p['subject'] && ($d['version'] ?? null) === $v['version'] + 1 && ($d['status'] ?? null) === ['accept' => 'accepted', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][substr($a, 7)];
            }
            if (($d['organization_id'] ?? null) !== $p['review']['organization_id'] || ($d['partner_id'] ?? null) !== $p['review']['partner_id'] || ! is_array($d['payments'] ?? null) || ! is_array($d['credits'] ?? null)) {
                return false;
            }
            if ($a === 'invoice') {
                foreach (['basis', 'mode', 'discount_amount', 'shipping_amount', 'tax_basis_points', 'rounding', 'note'] as $key) {
                    if (($d[$key] ?? null) !== $v[$key]) {
                        return false;
                    }
                }

                return ($d['version'] ?? null) === 1 && ($d['status'] ?? null) === 'pending' && substr($d['due_on'] ?? '', 0, 10) === $v['due_on'];
            }
            if ($d['id'] !== $p['subject'] || ($d['version'] ?? null) !== $v['version'] + 1) {
                return false;
            }
            if (str_starts_with($a, 'invoice_')) {
                return ($d['status'] ?? null) === ['accept' => 'accepted', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][substr($a, 8)];
            }
            if ($a === 'payment') {
                return collect($d['payments'])->contains(fn ($x) => ($x['invoice_id'] ?? null) === $d['id'] && ($x['amount'] ?? null) === $v['amount'] && ($x['reference'] ?? null) === $v['reference'] && ($x['method'] ?? null) === $v['method'] && ($x['kind'] ?? null) === $v['kind'] && ($x['status'] ?? null) === 'verified');
            }
            if ($a === 'reverse') {
                return collect($d['payments'])->contains(fn ($x) => ($x['id'] ?? null) === $p['child'] && ($x['invoice_id'] ?? null) === $d['id'] && ($x['status'] ?? null) === 'reversed' && ($x['reversal_reason'] ?? null) === $v['reason']);
            }
            if ($a === 'credit') {
                return collect($d['credits'])->contains(fn ($x) => ($x['invoice_id'] ?? null) === $d['id'] && ($x['case_id'] ?? null) === $v['case_id'] && ($x['base_amount'] ?? null) === $v['base_amount'] && ($x['tax_amount'] ?? null) === $v['tax_amount'] && ($x['status'] ?? null) === 'pending');
            }

            return collect($d['credits'])->contains(fn ($x) => ($x['invoice_id'] ?? null) === $d['id'] && ($x['id'] ?? null) === $p['child'] && ($x['status'] ?? null) === ['accept' => 'accepted', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][substr($a, 7)]);
        });
    }
}
