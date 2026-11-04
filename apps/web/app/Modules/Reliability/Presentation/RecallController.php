<?php

namespace App\Modules\Reliability\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class RecallController
{
    public function __construct(private Backend $api, private PendingMutation $mutations) {}

    public function index(Request $r, string $organization): mixed
    {
        $pending = session('recall_pending.'.$organization);
        $rows = null;
        $recalls = [];
        if (! $pending) {
            $v = $r->validate(['page' => 'sometimes|integer|min:1']);
            $c = $this->api->request('GET', "organizations/$organization/recalls", $v)->json();
            abort_unless(($c['organization_id'] ?? null) === $organization && is_array($c['data'] ?? null), 503);
            $rows = new LengthAwarePaginator($c['data'], $c['total'], 50, $c['current_page'], ['path' => url()->current()]);
            $f = $this->api->request('GET', "organizations/$organization/food-safety")->json();
            abort_unless(($f['organization_id'] ?? null) === $organization, 503);
            $recalls = $f['recalls']['data'];
        }

        return response()->view('pages.recalls', compact('organization', 'rows', 'pending', 'recalls'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $r, string $organization, string $campaign): mixed
    {
        $pending = session('recall_pending.'.$organization);
        $c = $rows = null;
        if (! $pending) {
            $v = $r->validate(['page' => 'sometimes|integer|min:1']);
            $c = $this->api->request('GET', "organizations/$organization/recalls/$campaign", $v)->json();
            abort_unless(($c['organization_id'] ?? null) === $organization && data_get($c, 'campaign.id') === $campaign && is_bool($c['is_source'] ?? null) && is_array(data_get($c, 'targets.data')), 503);
            $p = $c['targets'];
            foreach ($p['data'] as $row) {
                abort_unless(is_string($row['id'] ?? null) && ($c['is_source'] || ($row['organization_id'] ?? null) === $organization), 503);
            }$rows = new LengthAwarePaginator($p['data'], $p['total'], 50, $p['current_page'], ['path' => url()->current()]);
        }

        return response()->view('pages.recall', compact('organization', 'campaign', 'pending', 'c', 'rows'))->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $r, string $organization, ?string $campaign = null): mixed
    {
        abort_if(session()->has('recall_pending.'.$organization), 409);
        $action = $campaign ? $r->validate(['action' => 'required|in:refresh,close,hold,release'])['action'] : 'start';
        $rules = match ($action) {
            'start' => ['recall_id' => 'required|ulid', 'instructions' => 'required|string|max:4000'],'refresh' => [],'close' => ['version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'],default => ['target_id' => 'required|ulid', 'version' => 'required|integer|min:1', 'expected_revision' => 'required|integer|min:1', 'observed_pieces' => 'required|integer|min:0|max:1000000000', 'evidence_reference' => 'required|string|max:2000', 'reason' => 'required|string|max:2000']
        };
        $v = $r->validate(['operation_id' => 'required|uuid', ...$rules]);
        foreach (['version', 'expected_revision', 'observed_pieces'] as $k) {
            if (isset($v[$k])) {
                $v[$k] = (int) $v[$k];
            }
        }
        $target = $v['target_id'] ?? null;
        unset($v['target_id']);
        if (in_array($action, ['hold', 'release'])) {
            $v['action'] = $action;
        }
        $path = "organizations/$organization/recalls".($campaign ? "/$campaign/".($target ? "targets/$target" : $action) : '');

        return $this->send($organization, ['path' => $path, 'body' => $v, 'action' => $action, 'subject' => $target ?? $campaign, 'target' => "/app/organizations/$organization/recalls".($campaign ? "/$campaign" : '')]);
    }

    public function retry(string $organization): mixed
    {
        $p = session('recall_pending.'.$organization);
        abort_unless(is_array($p), 409);

        return $this->send($organization, $p);
    }

    private function send(string $org, array $p): mixed
    {
        return $this->mutations->send('recall_pending.'.$org, $p, function ($d, $p) use ($org) {
            if (($d['organization_id'] ?? null) !== $org || ! is_string($d['id'] ?? null)) {
                return false;
            }if ($p['action'] === 'start') {
                return ($d['batch_recall_id'] ?? null) === $p['body']['recall_id'] && ($d['status'] ?? null) === 'active';
            }if (($d['id'] ?? null) !== $p['subject']) {
                return false;
            }

            return $p['action'] === 'refresh' ? ($d['status'] ?? null) === 'active' : ($d['version'] ?? null) === $p['body']['version'] + 1 && ($d['status'] ?? null) === match ($p['action']) {
                'close' => 'closed','hold' => 'held','release' => 'released'
            };
        });
    }
}
