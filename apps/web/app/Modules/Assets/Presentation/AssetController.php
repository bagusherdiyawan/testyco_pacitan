<?php

namespace App\Modules\Assets\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class AssetController
{
    public function __construct(private Backend $api, private PendingMutation $mutations) {}

    public function index(Request $r, string $organization): mixed
    {
        $pending = session('asset_create_pending.'.$organization);
        $items = null;
        if (! $pending) {
            $v = $r->validate(['page' => 'sometimes|integer|min:1']);
            $c = $this->api->request('GET', "organizations/$organization/assets", $v)->json();
            abort_unless(($c['organization_id'] ?? null) === $organization && is_array($c['data'] ?? null) && ($c['current_page'] ?? null) === (int) ($v['page'] ?? 1) && ($c['per_page'] ?? null) === 50, 503);
            $items = new LengthAwarePaginator($c['data'], $c['total'], 50, $c['current_page'], ['path' => url()->current()]);
        }

        return response()->view('pages.assets', compact('organization', 'pending', 'items'))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, string $organization): mixed
    {
        abort_if(session()->has('asset_create_pending.'.$organization), 409);
        $v = $r->validate(['operation_id' => 'required|uuid', 'number' => 'required|string|max:80', 'kind' => 'required|in:freezer,pos_device,other', 'brand' => 'required|string|max:120', 'serial_number' => 'required|string|max:160', 'condition_note' => 'required|string|max:2000', 'ownership_reference' => 'required|string|max:2000']);

        return $this->sendCreate($organization, ['path' => "organizations/$organization/assets", 'body' => $v, 'target' => "/app/organizations/$organization/assets"]);
    }

    private function sendCreate(string $org, array $p): mixed
    {
        return $this->mutations->send('asset_create_pending.'.$org, $p, fn ($d, $p) => ($d['organization_id'] ?? null) === $org && is_string($d['id'] ?? null) && ($d['version'] ?? null) === 1 && ($d['number'] ?? null) === $p['body']['number'] && ($d['serial_number'] ?? null) === $p['body']['serial_number'] && ($d['kind'] ?? null) === $p['body']['kind'], fn ($d) => '/app/assets/'.$d['id']);
    }

    public function retryCreate(string $organization): mixed
    {
        $p = session('asset_create_pending.'.$organization);
        abort_unless(is_array($p), 409);

        return $this->sendCreate($organization, $p);
    }

    private function context(string $asset, array $q = []): array
    {
        $c = $this->api->request('GET', "assets/$asset", $q)->json();
        abort_unless(data_get($c, 'asset.id') === $asset && is_int(data_get($c, 'asset.version')) && is_bool($c['is_owner'] ?? null) && is_bool($c['can_confirm'] ?? null), 503);
        if ($c['active_placement'] ?? null) {
            abort_unless($c['active_placement']['business_asset_id'] === $asset && $c['active_placement']['active_asset_id'] === $asset, 503);
        }foreach (['placements' => 'page', 'incidents' => 'incidents_page'] as $key => $param) {
            $page = $c[$key] ?? null;
            abort_unless(is_array($page) && is_array($page['data'] ?? null) && ($page['per_page'] ?? null) === 50 && ($page['current_page'] ?? null) === (int) ($q[$param] ?? 1), 503);
            foreach ($page['data'] as $row) {
                abort_unless(($row['business_asset_id'] ?? null) === $asset, 503);
            }
        }

        return $c;
    }

    public function show(Request $r, string $asset): mixed
    {
        $pending = session('asset_pending.'.$asset);
        $c = $placements = $incidents = null;
        if (! $pending) {
            $q = $r->validate(['page' => 'sometimes|integer|min:1', 'incidents_page' => 'sometimes|integer|min:1']);
            $c = $this->context($asset, $q);
            foreach (['placements', 'incidents'] as $key) {
                $p = $c[$key];
                $$key = new LengthAwarePaginator($p['data'], $p['total'], 50, $p['current_page'], ['path' => url()->current(), 'pageName' => $key === 'placements' ? 'page' : 'incidents_page', 'query' => $q]);
            }
        }

        return response()->view('pages.asset', compact('asset', 'pending', 'c', 'placements', 'incidents'))->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $r, string $asset): mixed
    {
        abort_if(session()->has('asset_pending.'.$asset), 409);
        $h = $r->validate(['action' => 'required|in:place,accept,reject,withdraw,request_return,confirm_return,incident,resolve', 'subject' => 'nullable|ulid']);
        $a = $h['action'];
        $rules = match ($a) {
            'place' => ['store_id' => 'required|ulid', 'contract_id' => 'nullable|ulid', 'responsible_name' => 'required|string|max:120', 'handed_over_on' => 'required|date_format:Y-m-d', 'condition_note' => 'required|string|max:2000', 'agreement_reference' => 'required|string|max:2000', 'evidence_reference' => 'required|string|max:2000'],'incident' => ['description' => 'required|string|max:2000', 'evidence_reference' => 'required|string|max:2000', 'reported_on' => 'required|date_format:Y-m-d'],'resolve' => ['resolution' => 'required|string|max:2000', 'cost_basis_reference' => 'required|string|max:2000', 'owner_cost' => 'required|integer|min:0|max:1000000000000', 'recipient_cost' => 'required|integer|min:0|max:1000000000000', 'completed_on' => 'required|date_format:Y-m-d'],default => ['reason' => 'required|string|max:2000', 'consent' => 'sometimes|accepted', ...($a === 'request_return' ? ['returned_on' => 'required|date_format:Y-m-d', 'return_condition' => 'required|string|max:2000', 'return_reference' => 'required|string|max:2000'] : []), ...($a === 'confirm_return' ? ['disposition' => 'required|in:available,maintenance', 'inspection_note' => 'required|string|max:2000'] : [])]
        };
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', ...$rules]);
        $v['version'] = (int) $v['version'];
        foreach (['owner_cost', 'recipient_cost'] as $key) {
            if (isset($v[$key])) {
                $v[$key] = (int) $v[$key];
            }
        }if (isset($v['consent'])) {
            $v['consent'] = true;
        }$c = $this->context($asset);
        abort_unless($c['asset']['version'] === $v['version'], 409);
        $subject = $h['subject'] ?? null;
        if (! in_array($a, ['place', 'incident'], true)) {
            abort_unless($subject, 422);
        }$path = "assets/$asset".match ($a) {
            'place' => '/placements','incident' => '/incidents','resolve' => "/incidents/$subject/resolve",default => "/placements/$subject/$a"
        };

        return $this->send($asset, ['path' => $path, 'body' => $v, 'action' => $a, 'subject' => $subject, 'review' => $c, 'target' => "/app/assets/$asset"]);
    }

    public function retry(string $asset): mixed
    {
        $p = session('asset_pending.'.$asset);
        abort_unless(is_array($p), 409);

        return $this->send($asset, $p);
    }

    private function send(string $asset, array $p): mixed
    {
        return $this->mutations->send('asset_pending.'.$asset, $p, function ($d, $p) use ($asset) {
            if (data_get($d, 'asset.id') !== $asset || data_get($d, 'asset.organization_id') !== $p['review']['asset']['organization_id'] || data_get($d, 'asset.version') !== $p['body']['version'] + 1) {
                return false;
            }$a = $p['action'];
            $record = $d[in_array($a, ['incident', 'resolve'], true) ? 'incident' : 'placement'] ?? null;
            if (! is_array($record) || ($record['business_asset_id'] ?? null) !== $asset || ! is_string($record['id'] ?? null) || ($p['subject'] && $record['id'] !== $p['subject'])) {
                return false;
            }$status = ['place' => 'pending', 'accept' => 'active', 'reject' => 'rejected', 'withdraw' => 'withdrawn', 'request_return' => 'return_pending', 'confirm_return' => 'returned', 'incident' => 'open', 'resolve' => 'resolved'][$a];
            if (($record['status'] ?? null) !== $status) {
                return false;
            }if ($a === 'place') {
                return ($record['store_id'] ?? null) === $p['body']['store_id'] && ($record['agreement_reference'] ?? null) === $p['body']['agreement_reference'];
            }if ($a === 'incident') {
                return ($record['description'] ?? null) === $p['body']['description'];
            }if ($a === 'resolve') {
                return ($record['owner_cost'] ?? null) === $p['body']['owner_cost'] && ($record['recipient_cost'] ?? null) === $p['body']['recipient_cost'];
            }

            return true;
        });
    }
}
