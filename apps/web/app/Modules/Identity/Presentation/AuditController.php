<?php

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use Carbon\CarbonImmutable;
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
        $today = CarbonImmutable::now('Asia/Jakarta')->format('Y-m-d');
        $from = CarbonImmutable::parse($v['from_day'] ?? $today, 'Asia/Jakarta')->startOfDay();
        $to = CarbonImmutable::parse($v['to_day'] ?? $today, 'Asia/Jakarta')->startOfDay()->addDay();
        abort_unless($to->greaterThan($from) && $to->timestamp - $from->timestamp <= 31 * 86400, 422, 'Rentang maksimal 31 hari dan tanggal akhir tidak sebelum awal.');
        $exact = array_filter(array_intersect_key($v, array_flip(['action', 'subject_id', 'actor_id'])), fn ($value) => $value !== null && $value !== '');
        $page = (int) ($v['page'] ?? 1);
        $query = [...$exact, 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'page' => $page];
        $filters = [...$exact, 'from_day' => $from->format('Y-m-d'), 'to_day' => $to->subDay()->format('Y-m-d')];
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
