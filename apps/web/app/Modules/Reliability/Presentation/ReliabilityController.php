<?php

namespace App\Modules\Reliability\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class ReliabilityController
{
    public function __construct(private Backend $api, private PendingMutation $mutations) {}

    private function path(string $p): bool
    {
        return (bool) preg_match('~^/app/(organizations/[A-Za-z0-9_-]+(?:/food-safety|/recalls/[A-Za-z0-9_-]+|/commercial-agreements/[A-Za-z0-9_-]+)?|orders/[A-Za-z0-9_-]+(?:/billing)?|stores/[A-Za-z0-9_-]+/operations|assets/[A-Za-z0-9_-]+)$~D', $p);
    }

    public function fefo(Request $r, string $store): mixed
    {
        $v = $r->validate(['product_id' => 'sometimes|ulid', 'bucket' => 'required_with:product_id|in:usable,opened,ready', 'condition' => 'required_with:product_id|in:frozen,ready', 'pack_size' => 'required_if:bucket,usable|nullable|integer|min:1']);
        $products = $this->api->all("stores/$store/catalog");
        $result = null;
        if (isset($v['product_id'])) {
            $result = $this->api->request('GET', "stores/$store/food-safety/fefo", $v)->json();
            abort_unless(($result['store_id'] ?? null) === $store && ($result['product_id'] ?? null) === $v['product_id'] && ($result['bucket'] ?? null) === $v['bucket'] && in_array($result['mode'] ?? null, ['off', 'suggest', 'enforce'], true) && is_array($result['data'] ?? null), 503);
            foreach ($result['data'] as $b) {
                abort_unless(($b['store_id'] ?? null) === $store && ($b['product_id'] ?? null) === $v['product_id'] && is_int($b['available_pieces'] ?? null), 503);
            }
        }

        return response()->view('pages.fefo', compact('store', 'result', 'products'))->header('Cache-Control', 'private, no-store');
    }

    public function inbox(Request $r): mixed
    {
        $v = $r->validate(['page' => 'sometimes|integer|min:1']);
        $c = $this->api->request('GET', 'inbox', $v)->json();
        abort_unless(($c['user_id'] ?? null) === session('api_user.id') && is_array($c['data'] ?? null) && ($c['current_page'] ?? null) === (int) ($v['page'] ?? 1) && ($c['per_page'] ?? null) === 50, 503);
        foreach ($c['data'] as $row) {
            abort_unless(is_string($row['id'] ?? null) && is_string($row['title'] ?? null) && $this->path($row['local_path'] ?? ''), 503);
        }$entries = new LengthAwarePaginator($c['data'], $c['total'], 50, $c['current_page'], ['path' => url()->current()]);

        return response()->view('pages.inbox', compact('c', 'entries'))->header('Cache-Control', 'private, no-store');
    }

    public function mark(string $entry): mixed
    {
        $d = $this->api->request('POST', "inbox/$entry/read")->json('data');
        abort_unless(($d['id'] ?? null) === $entry && ($d['user_id'] ?? null) === session('api_user.id') && is_string($d['read_at'] ?? null), 503);

        return redirect('/app/inbox');
    }

    public function preferences(Request $r): mixed
    {
        $v = $r->validate(['version' => 'required|integer|min:0', 'email_enabled' => 'required|boolean']);
        $v['version'] = (int) $v['version'];
        $v['email_enabled'] = $r->boolean('email_enabled');
        $d = $this->api->request('POST', 'inbox/preferences', $v)->json('data');
        abort_unless(($d['version'] ?? null) === $v['version'] + 1 && ($d['email_enabled'] ?? null) === $v['email_enabled'], 503);

        return redirect('/app/inbox');
    }

    private function foodContext(string $org, array $q = []): array
    {
        $c = $this->api->request('GET', "organizations/$org/food-safety", $q)->json();
        abort_unless(($c['organization_id'] ?? null) === $org && is_array($c['products'] ?? null) && is_array(data_get($c, 'recalls.data')) && ($c['recalls']['current_page'] ?? null) === (int) ($q['page'] ?? 1), 503);
        if ($c['policy'] ?? null) {
            abort_unless($c['policy']['organization_id'] === $org && is_int($c['policy']['version']), 503);
        }foreach ($c['recalls']['data'] as $r) {
            abort_unless(($r['organization_id'] ?? null) === $org, 503);
        }

        abort_unless(is_array(data_get($c, 'ready_batches.data')) && ($c['ready_batches']['current_page'] ?? null) === (int) ($q['ready_page'] ?? 1), 503);
        foreach ($c['ready_batches']['data'] as $b) {
            abort_unless(($b['organization_id'] ?? null) === $org, 503);
        }

        return $c;
    }

    public function food(Request $r, string $organization): mixed
    {
        $pending = session('food_pending.'.$organization);
        $c = $rows = $readyRows = null;
        if (! $pending) {
            $q = $r->validate(['page' => 'sometimes|integer|min:1', 'ready_page' => 'sometimes|integer|min:1']);
            $c = $this->foodContext($organization, $q);
            $p = $c['recalls'];
            $rows = new LengthAwarePaginator($p['data'], $p['total'], 50, $p['current_page'], ['path' => url()->current(), 'query' => $q]);
            $p = $c['ready_batches'];
            $readyRows = new LengthAwarePaginator($p['data'], $p['total'], 50, $p['current_page'], ['path' => url()->current(), 'pageName' => 'ready_page', 'query' => $q]);
        }

        return response()->view('pages.food-safety', compact('organization', 'pending', 'c', 'rows', 'readyRows'))->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $r, string $organization): mixed
    {
        abort_if(session()->has('food_pending.'.$organization), 409);
        $h = $r->validate(['action' => 'required|in:policy,recall,withdraw,ready', 'subject' => 'nullable|ulid']);
        $action = $h['action'];
        $rules = match ($action) {
            'policy' => ['version' => 'required|integer|min:0', 'enabled' => 'required|boolean', 'block_expired' => 'required|boolean', 'fefo' => 'required|in:off,suggest,enforce', 'ready_life_minutes' => 'present|nullable|integer|min:1|max:525600', 'reference' => 'required|string|max:2000'],'recall' => ['product_id' => 'required|ulid', 'batch_code' => 'required|string|max:80', 'reason' => 'required|string|max:2000', 'reference' => 'required|string|max:2000'],'withdraw' => ['version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000'],default => ['batch_id' => 'required|ulid', 'expected_revision' => 'required|integer|min:1', 'prepared_at' => 'required|date_format:Y-m-d\TH:i', 'reference' => 'required|string|max:2000']
        };
        $v = $r->validate(['operation_id' => 'required|uuid', ...$rules]);
        foreach (['version', 'ready_life_minutes', 'expected_revision'] as $k) {
            if (isset($v[$k])) {
                $v[$k] = (int) $v[$k];
            }
        }foreach (['enabled', 'block_expired'] as $k) {
            if (isset($v[$k])) {
                $v[$k] = $r->boolean($k);
            }
        }if ($action === 'ready') {
            $v['prepared_at'] = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $v['prepared_at'], 'Asia/Jakarta')->toIso8601String();
        }$subject = $h['subject'] ?? null;
        if ($action === 'withdraw') {
            abort_unless($subject, 422);
        }$path = "organizations/$organization/food-safety".match ($action) {
            'policy' => '','recall' => '/recalls','withdraw' => "/recalls/$subject/withdraw",default => '/ready'
        };

        return $this->send($organization, ['path' => $path, 'body' => $v, 'action' => $action, 'subject' => $subject, 'target' => "/app/organizations/$organization/food-safety"]);
    }

    public function retry(string $organization): mixed
    {
        $p = session('food_pending.'.$organization);
        abort_unless(is_array($p), 409);

        return $this->send($organization, $p);
    }

    private function send(string $org, array $p): mixed
    {
        return $this->mutations->send('food_pending.'.$org, $p, function ($d, $p) use ($org) {
            if (($d['organization_id'] ?? null) !== $org) {
                return false;
            }$b = $p['body'];

            return match ($p['action']) {
                'policy' => ($d['version'] ?? null) === $b['version'] + 1 && ($d['enabled'] ?? null) === $b['enabled'] && ($d['block_expired'] ?? null) === $b['block_expired'] && ($d['fefo'] ?? null) === $b['fefo'] && ($d['ready_life_minutes'] ?? null) === ($b['ready_life_minutes'] ?? null),'recall' => is_string($d['id'] ?? null) && ($d['product_id'] ?? null) === $b['product_id'] && ($d['batch_code'] ?? null) === $b['batch_code'] && ($d['status'] ?? null) === 'active','withdraw' => ($d['id'] ?? null) === $p['subject'] && ($d['version'] ?? null) === $b['version'] + 1 && ($d['status'] ?? null) === 'withdrawn','ready' => ($d['id'] ?? null) === $b['batch_id'] && ($d['stock_revision'] ?? null) === $b['expected_revision'] + 1,default => false
            };
        });
    }
}
