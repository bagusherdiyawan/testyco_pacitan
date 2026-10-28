<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Infrastructure\SaleIntent;
use App\Modules\Shifts\Application\ShiftBlockers;

final class PendingIntents implements ShiftBlockers
{
    public function hasPending(string $shift): bool
    {
        return SaleIntent::where('cash_shift_id', $shift)->where('status', 'pending')->exists();
    }
}
