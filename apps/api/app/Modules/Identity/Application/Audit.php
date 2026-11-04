<?php

namespace App\Modules\Identity\Application;

use App\Modules\Reliability\Application\Inbox;
use App\Modules\Reliability\ReliabilityServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Audit
{
    public static function record(?int $actor, ?string $organization, string $action, string $subject, ?array $before, ?array $after): void
    {
        DB::transaction(function () use ($actor, $organization, $action, $subject, $before, $after) {
            $id = (string) Str::ulid();
            DB::table('audit_entries')->insert(['id' => $id, 'actor_id' => $actor, 'organization_id' => $organization, 'action' => $action, 'subject_id' => $subject, 'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            if (app()->getProvider(ReliabilityServiceProvider::class)) {
                app(Inbox::class)->capture($id, $organization, $action, $subject, $after);
            }
        });
    }
}
