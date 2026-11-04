<?php

namespace App\Modules\Commercial\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class CommercialController
{
    public function __construct(private Backend $api, private PendingMutation $mutations) {}

    private function paginator(array $p, string $param = 'page', array $q = []): LengthAwarePaginator
    {
        abort_unless(is_array($p['data'] ?? null) && ($p['per_page'] ?? null) === 50, 503);

        return new LengthAwarePaginator($p['data'], $p['total'], 50, $p['current_page'], ['path' => url()->current(), 'pageName' => $param, 'query' => $q]);
    }

    public function index(Request $r, string $organization): mixed
    {
        $pending = session('commercial_agreement_pending.'.$organization);
        $rows = $options = null;
        if (! $pending) {
            $v = $r->validate(['page' => 'sometimes|integer|min:1', 'order_id' => 'nullable|ulid', 'q' => 'nullable|string|max:80']);
            $c = $this->api->request('GET', "organizations/$organization/commercial-agreements", array_intersect_key($v, ['page' => true]))->json();
            abort_unless(($c['organization_id'] ?? null) === $organization, 503);
            foreach ($c['data'] ?? [] as $g) {
                abort_unless(in_array($organization, [$g['organization_id'] ?? null, $g['partner_id'] ?? null], true), 503);
            }$rows = $this->paginator($c);
            $options = $this->api->request('GET', "organizations/$organization/commercial-agreements/options", array_intersect_key($v, ['order_id' => true, 'q' => true]))->json();
            abort_unless(($options['organization_id'] ?? null) === $organization && is_array($options['contracts'] ?? null) && is_array($options['orders'] ?? null), 503);
        }

        return response()->view('pages.commercial-agreements', compact('organization', 'pending', 'rows', 'options'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $organization, string $agreement): mixed
    {
        $pending = session('commercial_agreement_pending.'.$organization);
        $c = $entries = $payments = null;
        if (! $pending) {
            $q = $r->validate(['page' => 'sometimes|integer|min:1', 'payments_page' => 'sometimes|integer|min:1']);
            $c = $this->api->request('GET', "organizations/$organization/commercial-agreements/$agreement", $q)->json();
            $g = $c['agreement'] ?? [];
            abort_unless(($c['organization_id'] ?? null) === $organization && ($g['id'] ?? null) === $agreement && in_array($organization, [$g['organization_id'] ?? null, $g['partner_id'] ?? null], true) && is_int($c['own_receivable'] ?? null), 503);
            foreach (['entries', 'payments'] as $key) {
                foreach ($c[$key]['data'] ?? [] as $row) {
                    abort_unless(($row['commercial_agreement_id'] ?? null) === $agreement, 503);
                }
            } $entries = $this->paginator($c['entries'], 'page', $q);
            $payments = $this->paginator($c['payments'], 'payments_page', $q);
        }

        return response()->view('pages.commercial-agreement', compact('organization', 'agreement', 'pending', 'c', 'entries', 'payments'))->header('Cache-Control', 'private, no-store');
    }

    public function propose(Request $r, string $organization): mixed
    {
        abort_if(session()->has('commercial_agreement_pending.'.$organization), 409);
        $v = $r->validate(['operation_id' => 'required|uuid', 'kind' => 'required|in:consignment,sponsored_promo', 'contract_id' => 'required|ulid', 'order_id' => 'nullable|ulid', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d', 'reference' => 'required|string|max:4000', 'terms' => 'required|array']);
        // Backend validates exact kind-dependent schema; only known fields are forwarded.
        $allowed = $v['kind'] === 'consignment' ? ['basis', 'supplier_basis_points', 'tax_basis_points', 'loss_policy', 'bundle_basis', 'costs', 'rounding', 'refund_policy', 'frequency', 'data_scope', 'movement_policy'] : ['sponsor_basis_points', 'budget_amount', 'budget_restore', 'voucher', 'data_scope'];
        $t = array_intersect_key($v['terms'], array_flip($allowed));
        foreach (['supplier_basis_points', 'tax_basis_points', 'sponsor_basis_points', 'budget_amount'] as $k) {
            if (isset($t[$k]) && preg_match('/^[0-9]+$/D', (string) $t[$k])) {
                $t[$k] = (int) $t[$k];
            }
        }
        $t['data_scope'] = $v['terms']['data_scope'] ?? null;
        if ($v['kind'] === 'consignment') {
            $t['costs'] = ($t['basis'] ?? null) === 'net_sales' ? [] : array_values($t['costs'] ?? []);
            foreach ($t['costs'] as &$row) {
                $row = array_intersect_key($row, array_flip(['order_line_id', 'cost_per_piece', 'evidence_reference']));
                if (isset($row['cost_per_piece']) && preg_match('/^[0-9]+$/D', (string) $row['cost_per_piece'])) {
                    $row['cost_per_piece'] = (int) $row['cost_per_piece'];
                }
            }unset($row);
        } else {
            unset($v['order_id']);
            $t['voucher'] = array_intersect_key($t['voucher'] ?? [], array_flip(['code', 'kind', 'value', 'maximum_discount', 'minimum_subtotal', 'usage_limit', 'store_ids', 'product_ids', 'restore_policy', 'terms']));
            $t['voucher']['product_ids'] = array_values($t['voucher']['product_ids'] ?? []);
            foreach (['value', 'maximum_discount', 'minimum_subtotal', 'usage_limit'] as $k) {
                if (isset($t['voucher'][$k]) && preg_match('/^[0-9]+$/D', (string) $t['voucher'][$k])) {
                    $t['voucher'][$k] = (int) $t['voucher'][$k];
                }
            }
        }
        $v['terms'] = $t;

        return $this->send($organization, ['path' => "organizations/$organization/commercial-agreements", 'body' => $v, 'action' => 'propose', 'target' => "/app/organizations/$organization/commercial-agreements"]);
    }

    public function submit(Request $r, string $organization, string $agreement): mixed
    {
        abort_if(session()->has('commercial_agreement_pending.'.$organization), 409);
        $a = $r->validate(['action' => 'required|in:accept,reject,withdraw,suspend,payment'])['action'];
        $rules = $a === 'payment' ? ['direction' => 'required|in:collection,payout', 'amount' => 'required|integer|min:1|max:1000000000000', 'method' => 'required|in:bank_transfer,cash', 'reference' => 'required|string|max:160', 'evidence_reference' => 'required|string|max:2000', 'paid_on' => 'required|date_format:Y-m-d', 'verified' => 'required|accepted'] : ['reason' => 'required|string|max:2000', ...($a === 'accept' ? ['consent' => 'required|accepted', 'sharing_consented' => 'required|accepted'] : [])];
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', ...$rules]);
        $v['version'] = (int) $v['version'];
        if (isset($v['amount'])) {
            $v['amount'] = (int) $v['amount'];
        }foreach (['verified', 'consent', 'sharing_consented'] as $k) {
            if (isset($v[$k])) {
                $v[$k] = true;
            }
        }

        return $this->send($organization, ['path' => "organizations/$organization/commercial-agreements/$agreement/".($a === 'payment' ? 'payments' : $a), 'body' => $v, 'action' => $a, 'subject' => $agreement, 'target' => "/app/organizations/$organization/commercial-agreements/$agreement"]);
    }

    public function retry(string $organization): mixed
    {
        $p = session('commercial_agreement_pending.'.$organization);
        abort_unless(is_array($p), 409);

        return $this->send($organization, $p);
    }

    private function send(string $org, array $p): mixed
    {
        return $this->mutations->send('commercial_agreement_pending.'.$org, $p, function ($d, $p) use ($org) {
            if ($p['action'] === 'payment') {
                return ($d['organization_id'] ?? null) === $org && ($d['agreement_id'] ?? null) === $p['subject'] && ($d['version'] ?? null) === $p['body']['version'] + 1 && data_get($d, 'payment.reference') === $p['body']['reference'] && data_get($d, 'payment.signed_amount') === ($p['body']['direction'] === 'collection' ? $p['body']['amount'] : -$p['body']['amount']);
            }if (! in_array($org, [$d['organization_id'] ?? null, $d['partner_id'] ?? null], true) || ! is_string($d['id'] ?? null)) {
                return false;
            }if ($p['action'] === 'propose') {
                return ($d['proposer_organization_id'] ?? null) === $org && ($d['status'] ?? null) === 'pending' && ($d['contract_id'] ?? null) === $p['body']['contract_id'] && ($d['terms'] ?? null) === $p['body']['terms'];
            }

            return $d['id'] === $p['subject'] && ($d['version'] ?? null) === $p['body']['version'] + 1 && ($d['status'] ?? null) === match ($p['action']) {
                'accept' => 'accepted','reject' => 'rejected','withdraw' => 'withdrawn','suspend' => 'suspended'
            };
        });
    }
}
