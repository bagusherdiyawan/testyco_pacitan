<?php

namespace App\Modules\Partnership\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\PendingMutation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class ContractController
{
    public function __construct(private Backend $api, private PendingMutation $mutations) {}

    private function slot(string $org): string
    {
        return 'contract_pending.'.$org;
    }

    public function index(Request $r, string $organization): mixed
    {
        $pending = session($this->slot($organization));
        if ($pending) {
            return response()->view('pages.contracts', compact('organization', 'pending'))->header('Cache-Control', 'private, no-store');
        }
        $v = $r->validate(['page' => 'sometimes|integer|min:1']);
        $c = $this->api->request('GET', "organizations/$organization/contracts", $v)->json();
        abort_unless(($c['organization_id'] ?? null) === $organization && is_array($c['data'] ?? null) && ($c['per_page'] ?? null) === 50, 503, 'Cakupan kontrak tidak valid.');
        foreach ($c['data'] as $row) {
            abort_unless(in_array($organization, [$row['organization_id'] ?? null, $row['partner_id'] ?? null], true), 503, 'Kontrak bukan milik organisasi ini.');
        }
        $contracts = new LengthAwarePaginator($c['data'], $c['total'], 50, $c['current_page'], ['path' => url()->current()]);
        $partners = $c['can_create'] ? $this->api->all('organizations') : [];

        return response()->view('pages.contracts', compact('organization', 'pending', 'c', 'contracts', 'partners'))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $r, string $organization): mixed
    {
        abort_if(session()->has($this->slot($organization)), 409, 'Selesaikan retry terlebih dahulu.');
        $v = $r->validate(['attachment' => 'nullable|file|max:5120', 'operation_id' => 'required|uuid', 'partner_id' => 'required|ulid', 'title' => 'required|string|max:160', 'mode' => 'required|in:buyout,consignment', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'terms' => 'required|string|max:30000', 'brand_terms' => 'required|string|max:10000', 'data_scope' => 'required|string|max:10000', 'facilities' => 'required|string|max:10000']);

        if ($r->hasFile('attachment')) {
            $v['attachment_base64'] = base64_encode($r->file('attachment')->getContent());
        }unset($v['attachment']);

        return $this->send($organization, ['path' => "organizations/$organization/contracts", 'body' => $v, 'target' => "/app/organizations/$organization/contracts", 'expected_status' => 'draft', 'version' => 1, 'contract_id' => null, 'organization_id' => $organization]);
    }

    public function attachment(string $organization, string $contract): mixed
    {
        $file = $this->api->request('GET', "organizations/$organization/contracts/$contract/attachment");
        abort_unless($file->header('Content-Type') === 'application/pdf', 503);

        return response($file->body())->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="contract.pdf"')->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff')->header('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    public function decide(Request $r, string $organization, string $contract, string $action): mixed
    {
        abort_if(session()->has($this->slot($organization)), 409, 'Selesaikan retry terlebih dahulu.');
        abort_unless(in_array($action, ['publish', 'withdraw', 'accept', 'reject'], true), 404);
        $v = $r->validate(['operation_id' => 'required|uuid', 'version' => 'required|integer|min:1', 'reason' => in_array($action, ['withdraw', 'reject'], true) ? 'required|string|max:2000' : 'nullable|string|max:2000', 'consent' => $action === 'accept' ? 'required|accepted' : 'prohibited', 'consent_name' => $action === 'accept' ? 'required|string|max:160' : 'prohibited']);
        if ($action === 'accept') {
            $v['consent'] = true;
        }

        return $this->send($organization, ['path' => "organizations/$organization/contracts/$contract/$action", 'body' => $v, 'target' => "/app/organizations/$organization/contracts", 'expected_status' => ['publish' => 'published', 'withdraw' => 'withdrawn', 'accept' => 'accepted', 'reject' => 'rejected'][$action], 'version' => (int) $v['version'] + 1, 'contract_id' => $contract, 'organization_id' => $organization]);
    }

    public function retry(string $organization): mixed
    {
        $p = session($this->slot($organization));
        abort_unless(is_array($p) && ($p['organization_id'] ?? null) === $organization, 409);

        return $this->send($organization, $p);
    }

    private function send(string $org, array $p): mixed
    {
        return $this->mutations->send($this->slot($org), $p, function ($d, $p) use ($org) {
            if ($p['contract_id'] === null) {
                foreach (['partner_id', 'title', 'mode', 'starts_on', 'ends_on', 'terms', 'brand_terms', 'data_scope', 'facilities'] as $key) {
                    if (($d[$key] ?? null) !== $p['body'][$key]) {
                        return false;
                    }
                }
            }
            if ($p['expected_status'] === 'accepted' && ($d['consent_name'] ?? null) !== $p['body']['consent_name']) {
                return false;
            }

            return is_string($d['id'] ?? null) && ($p['contract_id'] === null || $d['id'] === $p['contract_id']) && in_array($org, [$d['organization_id'] ?? null, $d['partner_id'] ?? null], true) && ($d['version'] ?? null) === $p['version'] && ($d['status'] ?? null) === $p['expected_status'];
        });
    }
}
