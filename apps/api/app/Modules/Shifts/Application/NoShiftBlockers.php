<?php

namespace App\Modules\Shifts\Application;

final class NoShiftBlockers implements ShiftBlockers
{
    public function hasPending(string $shift): bool
    {
        return false;
    }
}
