<?php

namespace App\Modules\Promotions\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class VoucherController
{
    public function __construct(private Backend $api, private PendingMutation $mutations) {}

    public function show(Request $r, string $organization): mixed
    {
        $pending = session('voucher_pending.'.$organization);
        $items = $stores = $products = null;
        if (! $pending) {
            $v = $r->validate(['page' => 'sometimes|integer|min:1']);
            $c = $this->api->request('GET', "organizations/$organization/vouchers", $v)->json();
            abort_unless(($c['organization_id'] ?? null) === $organization && is_array($c['data'] ?? null) && ($c['current_page'] ?? null) === (int) ($v['page'] ?? 1) && ($c['per_page'] ?? null) === 50 && is_int($c['total'] ?? null), 503);
            foreach ($c['data'] as $row) {
                abort_unless(($row['organization_id'] ?? null) === $organization && ($row['funding_organization_id'] ?? null) === $organization, 503);
            }$items = new LengthAwarePaginator($c['data'], $c['total'], 50, $c['current_page'], ['path' => url()->current()]);
            $stores = $this->api->all("organizations/$organization/stores");
            $products = $this->api->all("organizations/$organization/products");
            foreach ([...$stores, ...$products] as $row) {
                abort_unless(($row['organization_id'] ?? null) === $organization, 503);
            }
        }

        return response()->view('pages.vouchers', compact('organization', 'pending', 'items', 'stores', 'products'))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, string $organization): mixed
    {
        abort_if(session()->has('voucher_pending.'.$organization), 409);
        $v = $r->validate(['operation_id' => 'required|uuid', 'code' => 'required|string|max:60|regex:/^[A-Z0-9_-]+$/', 'funding_organization_id' => 'required|ulid', 'kind' => 'required|in:fixed,percent', 'value' => 'required|integer|min:1|max:1000000000000', 'maximum_discount' => 'required|integer|min:1|max:1000000000000', 'minimum_subtotal' => 'required|integer|min:0|max:1000000000000', 'usage_limit' => 'required|integer|min:1|max:1000000', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'store_ids' => 'required|array|min:1|max:100', 'store_ids.*' => 'required|ulid|distinct', 'product_ids' => 'sometimes|array|max:100', 'product_ids.*' => 'required|ulid|distinct', 'restore_policy' => 'required|in:never,on_full_refund', 'terms' => 'required|string|max:2000']);
        abort_unless($v['funding_organization_id'] === $organization, 422);
        $v['product_ids'] ??= [];
        foreach (['value', 'maximum_discount', 'minimum_subtotal', 'usage_limit'] as $key) {
            $v[$key] = (int) $v[$key];
        }

        return $this->send($organization, ['path' => "organizations/$organization/vouchers", 'body' => $v, 'target' => "/app/organizations/$organization/vouchers", 'action' => 'create']);
    }

    public function status(Request $r, string $organization, string $voucher): mixed
    {
        abort_if(session()->has('voucher_pending.'.$organization), 409);
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'active' => 'required|boolean', 'reason' => 'required|string|max:2000']);
        $v['version'] = (int) $v['version'];
        $v['active'] = $r->boolean('active');

        return $this->send($organization, ['path' => "organizations/$organization/vouchers/$voucher/status", 'body' => $v, 'subject' => $voucher, 'target' => "/app/organizations/$organization/vouchers", 'action' => 'status']);
    }

    public function retry(string $organization): mixed
    {
        $p = session('voucher_pending.'.$organization);
        abort_unless(is_array($p), 409);

        return $this->send($organization, $p);
    }

    private function send(string $organization, array $p): mixed
    {
        return $this->mutations->send('voucher_pending.'.$organization, $p, function ($d, $p) use ($organization) {
            if (($d['organization_id'] ?? null) !== $organization || ($d['funding_organization_id'] ?? null) !== $organization || ! is_string($d['id'] ?? null)) {
                return false;
            }$b = $p['body'];
            if ($p['action'] === 'status') {
                return $d['id'] === $p['subject'] && ($d['active'] ?? null) === $b['active'] && ($d['version'] ?? null) === $b['version'] + 1;
            }foreach (['code', 'kind', 'value', 'maximum_discount', 'minimum_subtotal', 'usage_limit', 'restore_policy', 'terms', 'store_ids', 'product_ids'] as $key) {
                if (($d[$key] ?? null) !== $b[$key]) {
                    return false;
                }
            }

            return ($d['version'] ?? null) === 1 && substr($d['starts_on'] ?? '', 0, 10) === $b['starts_on'] && substr($d['ends_on'] ?? '', 0, 10) === $b['ends_on'];
        });
    }
}
