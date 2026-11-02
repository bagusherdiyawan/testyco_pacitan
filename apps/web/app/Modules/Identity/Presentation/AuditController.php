<?php

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class AuditController
{
    public function __construct(private Backend $api) {}

    public function index(Request $r, string $organization): mixed
    {
        [$query, $filters, $page] = AuditSearch::parse($r);
        $query['page'] = $page;
        $payload = $this->api->request('GET', "organizations/$organization/audit", $query)->json();
        abort_unless(is_array($payload)
            && data_get($payload, 'organization.id') === $organization
            && in_array($payload['visibility'] ?? null, ['organization', 'onboarding'], true)
            && is_array($payload['data'] ?? null), 503, 'Cakupan respons audit API tidak valid.');
        foreach ($payload['data'] as $entry) {
            abort_unless(is_array($entry) && ($entry['organization_id'] ?? null) === $organization, 503, 'Catatan audit bukan milik organisasi yang dipilih.');
        }
        abort_unless(is_int($payload['total'] ?? null) && $payload['total'] >= 0
            && is_int($payload['per_page'] ?? null) && $payload['per_page'] > 0
            && is_int($payload['current_page'] ?? null) && $payload['current_page'] === $page, 503, 'Pagination audit API tidak valid.');
        $entries = new LengthAwarePaginator($payload['data'], $payload['total'], $payload['per_page'], $page, ['path' => url()->current(), 'query' => $filters]);

        return response()->view('pages.audit', ['organization' => $organization, 'metadata' => $payload, 'entries' => $entries, 'filters' => $filters])
            ->header('Cache-Control', 'private, no-store');
    }
}
