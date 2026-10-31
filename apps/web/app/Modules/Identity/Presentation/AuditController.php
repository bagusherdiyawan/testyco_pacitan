<?php

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\WibPeriod;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class AuditController
{
    public function __construct(private Backend $api) {}

    public function index(Request $r, string $organization): mixed
    {
        $v = $r->validate([
            'from_day' => 'sometimes|date_format:Y-m-d',
            'to_day' => 'sometimes|date_format:Y-m-d',
            'action' => 'nullable|string|max:80',
            'subject_id' => 'nullable|string|max:80',
            'actor_id' => 'nullable|integer|min:1',
            'page' => 'sometimes|integer|min:1',
        ]);
        $period = WibPeriod::fromDays($v['from_day'] ?? null, $v['to_day'] ?? null);
        $exact = array_filter(array_intersect_key($v, array_flip(['action', 'subject_id', 'actor_id'])), fn ($value) => $value !== null && $value !== '');
        $page = (int) ($v['page'] ?? 1);
        $query = [...$exact, ...$period->api(), 'page' => $page];
        $filters = [...$exact, ...$period->calendar()];
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
