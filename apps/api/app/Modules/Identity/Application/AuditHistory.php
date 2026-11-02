<?php

namespace App\Modules\Identity\Application;

use App\Models\User;
use App\Modules\Identity\Infrastructure\Organization;
use App\Shared\Application\BoundedExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AuditHistory
{
    public const ONBOARDING_ACTIONS = ['organization.created', 'organization.updated', 'store.created', 'store.updated', 'user.created', 'user.deactivated', 'user.reactivated', 'membership.updated'];

    public function __construct(private Access $access) {}

    private function scope(User $user, string $organization, array $filters): array
    {
        $this->access->manage($user, $organization);
        $org = Organization::findOrFail($organization);
        $full = $this->access->role($user, $organization)?->manages() ?? false;
        $q = DB::table('audit_entries')->where('organization_id', $organization);
        if (! $full) {
            $q->whereIn('action', self::ONBOARDING_ACTIONS);
        }
        foreach (['action', 'actor_id', 'subject_id'] as $field) {
            if (isset($filters[$field])) {
                $q->where($field, $filters[$field]);
            }
        }
        if (isset($filters['from'], $filters['to'])) {
            $from = CarbonImmutable::parse($filters['from'])->utc();
            $to = CarbonImmutable::parse($filters['to'])->utc();
            abort_if($to->timestamp - $from->timestamp > 31 * 86400, 422, 'Rentang audit maksimal 31 hari.');
            $q->where('created_at', '>=', $from)->where('created_at', '<', $to);
        }

        return [$q, $org, $full ? 'organization' : 'onboarding'];
    }

    private function entry(object $row): object
    {
        $row->occurred_at = CarbonImmutable::parse($row->created_at, 'UTC')->utc()->toIso8601String();
        $row->actor_id = $row->actor_id === null ? null : (int) $row->actor_id;

        return $row;
    }

    public function read(User $user, string $organization, array $filters): array
    {
        [$q, $org, $visibility] = $this->scope($user, $organization, $filters);
        $rows = $q->orderByDesc('id')->paginate(50)->through(fn ($row) => $this->entry($row));

        return [...$rows->toArray(), 'organization' => ['id' => $org->id, 'name' => $org->name], 'visibility' => $visibility, 'observed_at' => now()->utc()->toIso8601String()];
    }

    public function export(User $user, string $organization, array $filters): array
    {
        [$q, $org, $visibility] = $this->scope($user, $organization, $filters);
        abort_unless(isset($filters['from'], $filters['to']), 422, 'Ekspor audit membutuhkan periode dengan timezone.');
        $rows = BoundedExport::collect($q->orderByDesc('id')->limit(BoundedExport::MAX_ROWS + 1)->cursor()->map(fn ($row) => $this->entry($row)), 'audit');

        return ['data' => $rows, 'row_count' => count($rows), 'organization' => ['id' => $org->id, 'name' => $org->name], 'visibility' => $visibility, 'observed_at' => now()->utc()->toIso8601String()];
    }
}
