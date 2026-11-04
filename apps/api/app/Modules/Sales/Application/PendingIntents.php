<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Infrastructure\OfflinePermit;
use App\Modules\Sales\Infrastructure\OfflineSale;
use App\Modules\Sales\Infrastructure\SaleIntent;
use App\Modules\Shifts\Application\ShiftBlockers;

final class PendingIntents implements ShiftBlockers
{
    public function hasPending(string $shift): bool
    {
        return OfflinePermit::where('cash_shift_id', $shift)->whereNull('closed_at')->exists() || OfflineSale::where('cash_shift_id', $shift)->where('status', 'conflict')->exists() || SaleIntent::where('cash_shift_id', $shift)->where('status', 'pending')->exists();
    }
}
