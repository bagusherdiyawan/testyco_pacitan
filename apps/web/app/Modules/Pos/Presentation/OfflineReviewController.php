<?php

namespace App\Modules\Pos\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class OfflineReviewController
{
    public function __construct(private Backend $api, private PendingMutation $mutations) {}

    public function index(Request $r, string $store): mixed
    {
        $pending = session('offline_review_pending.'.$store);
        $c = $rows = null;
        if (! $pending) {
            $v = $r->validate(['page' => 'sometimes|integer|min:1']);
            $c = $this->api->request('GET', "stores/$store/sales/offline", $v)->json();
            abort_unless(($c['store_id'] ?? null) === $store && is_bool($c['can_review'] ?? null) && is_array($c['data'] ?? null) && is_array($c['open_shifts'] ?? null) && ($c['current_page'] ?? null) === (int) ($v['page'] ?? 1) && ($c['per_page'] ?? null) === 50, 503);
            foreach ($c['data'] as $row) {
                abort_unless(($row['store_id'] ?? null) === $store && is_array($row['payload'] ?? null), 503);
            }foreach ($c['open_shifts'] as $shift) {
                abort_unless(($shift['store_id'] ?? null) === $store && ($shift['status'] ?? null) === 'open', 503);
            }$rows = new LengthAwarePaginator($c['data'], $c['total'], 50, $c['current_page'], ['path' => url()->current()]);
        }

        return response()->view('pages.pos.offline-review', compact('store', 'pending', 'c', 'rows'))->header('Cache-Control', 'private, no-store');
    }

    public function submit(Request $r, string $store, string $offline, string $action): mixed
    {
        abort_if(session()->has('offline_review_pending.'.$store), 409);
        $rules = ['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => 'required|string|max:2000', 'reconciliation_reference' => 'required|string|max:2000', 'verified' => 'required|accepted'];
        if ($action === 'accept') {
            $pair = explode(':', (string) $r->input('shift', ''), 2);
            $r->merge(['shift_id' => $pair[0] ?? null, 'shift_version' => $pair[1] ?? null]);
            $rules += ['shift_id' => 'required|ulid', 'shift_version' => 'required|integer|min:1'];
        }$v = $r->validate($rules);
        $v['version'] = (int) $v['version'];
        if (isset($v['shift_version'])) {
            $v['shift_version'] = (int) $v['shift_version'];
        }$v['verified'] = true;
        $d = $this->api->request('GET', "sales/offline/$offline")->json('data');
        abort_unless(($d['id'] ?? null) === $offline && ($d['store_id'] ?? null) === $store && ($d['status'] ?? null) === 'conflict' && ($d['version'] ?? null) === $v['version'], 409);

        return $this->send($store, ['path' => "sales/offline/$offline/$action", 'body' => $v, 'action' => $action, 'subject' => $offline, 'review' => $d, 'target' => "/app/stores/$store/pos/offline"]);
    }

    public function retry(string $store): mixed
    {
        $p = session('offline_review_pending.'.$store);
        abort_unless(is_array($p), 409);

        return $this->send($store, $p);
    }

    private function send(string $store, array $p): mixed
    {
        return $this->mutations->send('offline_review_pending.'.$store, $p, fn ($d, $p) => ($d['id'] ?? null) === $p['subject'] && ($d['store_id'] ?? null) === $store && ($d['operation_id'] ?? null) === $p['review']['operation_id'] && ($d['version'] ?? null) === $p['body']['version'] + 1 && ($d['status'] ?? null) === ($p['action'] === 'accept' ? 'accepted' : 'voided') && ($p['action'] !== 'accept' || is_string($d['sale_id'] ?? null)));
    }
}
