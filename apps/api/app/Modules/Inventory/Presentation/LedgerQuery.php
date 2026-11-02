<?php

namespace App\Modules\Inventory\Presentation;

use App\Modules\Inventory\Application\LedgerHistory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LedgerQuery
{
    public static function validate(Request $r, bool $export = false): array
    {
        return $r->validate([
            ...($export ? [] : ['page' => 'sometimes|integer|min:1']),
            'batch_id' => 'sometimes|required|ulid', 'q' => 'sometimes|nullable|string|max:100',
            'source_type' => ['sometimes', 'required', Rule::in(LedgerHistory::SOURCES)],
            'bucket' => 'sometimes|required|in:usable,opened,ready,quarantined,waste',
            'from' => [$export ? 'required' : 'nullable', 'required_with:to', 'string', 'date', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'],
            'to' => [$export ? 'required' : 'nullable', 'required_with:from', 'string', 'date', 'after:from', 'regex:/(Z|[+-][0-9]{2}:[0-9]{2})$/'],
        ]);
    }
}
