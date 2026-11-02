<?php

namespace App\Modules\Operations\Presentation;

use App\Shared\Application\WibPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LedgerSearch
{
    public static function parse(Request $r, bool $export = false): array
    {
        $v = $r->validate([
            'from_day' => $export ? 'required_with:to_day|date_format:Y-m-d' : 'sometimes|date_format:Y-m-d',
            'to_day' => $export ? 'required_with:from_day|date_format:Y-m-d' : 'sometimes|date_format:Y-m-d',
            'batch_id' => 'nullable|ulid', 'q' => 'nullable|string|max:100',
            ...($export ? [] : ['page' => 'sometimes|integer|min:1']),
            'source_type' => ['nullable', Rule::in(array_keys(StockLedgerController::SOURCES))],
            'bucket' => ['nullable', Rule::in(array_keys(StockLedgerController::BUCKETS))],
        ]);
        $period = WibPeriod::fromDays($v['from_day'] ?? null, $v['to_day'] ?? null);
        $selected = array_filter(array_intersect_key($v, array_flip(['batch_id', 'q', 'source_type', 'bucket'])), fn ($v) => $v !== null && $v !== '');

        return [[...$period->api(), ...$selected], [...$period->calendar(), ...$selected], (int) ($v['page'] ?? 1)];
    }
}
