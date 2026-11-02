<?php

namespace App\Modules\Identity\Presentation;

use App\Shared\Application\WibPeriod;
use Illuminate\Http\Request;

final class AuditSearch
{
    public static function parse(Request $r, bool $export = false): array
    {
        $v = $r->validate([
            'from_day' => $export ? 'required_with:to_day|date_format:Y-m-d' : 'sometimes|date_format:Y-m-d',
            'to_day' => $export ? 'required_with:from_day|date_format:Y-m-d' : 'sometimes|date_format:Y-m-d',
            'action' => 'nullable|string|max:80', 'subject_id' => 'nullable|string|max:80', 'actor_id' => 'nullable|integer|min:1',
            ...($export ? [] : ['page' => 'sometimes|integer|min:1']),
        ]);
        $period = WibPeriod::fromDays($v['from_day'] ?? null, $v['to_day'] ?? null);
        $exact = array_filter(array_intersect_key($v, array_flip(['action', 'subject_id', 'actor_id'])), fn ($value) => $value !== null && $value !== '');

        return [[...$exact, ...$period->api()], [...$exact, ...$period->calendar()], (int) ($v['page'] ?? 1)];
    }
}
