<?php

namespace App\Modules\Shifts\Application;

final class NoShiftBlockers implements ShiftBlockers
{
    public function hasPending(string $shift): bool
    {
        return false;
    }

    public function prepareForceClose(string $shift): void {}

    public function noncashSummary(string $shift): array
    {
        return [
            'qris_count' => 0,
            'qris_amount' => 0,
            'transfer_count' => 0,
            'transfer_amount' => 0,
            'total_noncash_sales' => 0,
            'total_noncash_refunds' => 0,
        ];
    }
}
