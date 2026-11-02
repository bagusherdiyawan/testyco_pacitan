<?php

namespace App\Modules\Identity\Presentation;

use App\Modules\Identity\Infrastructure\Backend;
use App\Shared\Application\CsvDownload;
use App\Shared\Application\ExportTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class AuditExportController
{
    public function __construct(private Backend $api) {}

    public function __invoke(Request $r, string $organization): mixed
    {
        [$query] = AuditSearch::parse($r, true);
        $response = $this->api->request('GET', "organizations/$organization/audit/export", $query);
        abort_if(strlen($response->body()) > 9 * 1024 * 1024, 503, 'Payload ekspor audit API terlalu besar.');
        $data = $response->json();
        abort_unless(is_array($data) && data_get($data, 'organization.id') === $organization
            && in_array($data['visibility'] ?? null, ['organization', 'onboarding'], true)
            && is_array($data['data'] ?? null) && count($data['data']) <= 10000
            && ($data['row_count'] ?? null) === count($data['data']), 503, 'Cakupan ekspor audit API tidak valid.');
        $rows = [];
        $ids = [];
        foreach ($data['data'] as $entry) {
            abort_unless(is_array($entry) && ($entry['organization_id'] ?? null) === $organization
                && is_string($entry['id'] ?? null) && $entry['id'] !== '' && ! isset($ids[$entry['id']])
                && is_string($entry['action'] ?? null) && array_key_exists('subject_id', $entry) && (is_string($entry['subject_id']) || $entry['subject_id'] === null)
                && array_key_exists('actor_id', $entry) && (is_int($entry['actor_id']) || $entry['actor_id'] === null)
                && ExportTime::valid($entry['occurred_at'] ?? null)
                && array_key_exists('before', $entry) && (is_string($entry['before']) || $entry['before'] === null)
                && array_key_exists('after', $entry) && (is_string($entry['after']) || $entry['after'] === null), 503, 'Baris ekspor audit API tidak valid.');
            $ids[$entry['id']] = true;
            $rows[] = array_map(fn ($v) => (string) $v, [
                $entry['id'], $organization, $data['visibility'], CarbonImmutable::parse($entry['occurred_at'])->utc()->toIso8601String(),
                $entry['actor_id'] ?? '', $entry['action'], $entry['subject_id'] ?? '', $entry['before'] ?? 'null', $entry['after'] ?? 'null',
            ]);
        }

        return CsvDownload::response('audit.csv', ['audit_id', 'organization_id', 'visibility', 'occurred_at_utc', 'actor_id', 'action', 'subject_id', 'before_json', 'after_json'], $rows);
    }
}
