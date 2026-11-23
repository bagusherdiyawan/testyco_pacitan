<?php

namespace App\Modules\Shifts\Application;

interface ShiftBlockers
{
    public function hasPending(string $shift): bool;

    public function prepareForceClose(string $shift): void;

    /** @return array{qris_count: int, qris_amount: int, transfer_count: int, transfer_amount: int, total_noncash_sales: int, total_noncash_refunds: int} */
    public function noncashSummary(string $shift): array;
}
