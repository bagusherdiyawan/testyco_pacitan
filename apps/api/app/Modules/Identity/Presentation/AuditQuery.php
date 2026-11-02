<?php

namespace App\Modules\Identity\Presentation;

use Illuminate\Http\Request;

final class AuditQuery
{
    public static function validate(Request $r, bool $export = false): array
    {
        return $r->validate([
            ...($export ? [] : ['page' => 'sometimes|integer|min:1']),
            'action' => 'nullable|string|max:80',
            'subject_id' => 'nullable|string|max:80',
            'actor_id' => 'nullable|integer|min:1',
            'from' => [$export ? 'required' : 'nullable', 'required_with:to', 'string', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'],
            'to' => [$export ? 'required' : 'nullable', 'required_with:from', 'string', 'date', 'after:from', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'],
        ]);
    }
}
