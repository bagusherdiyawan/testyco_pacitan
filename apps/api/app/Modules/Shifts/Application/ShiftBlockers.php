<?php

namespace App\Modules\Shifts\Application;

interface ShiftBlockers
{
    public function hasPending(string $shift): bool;
}
