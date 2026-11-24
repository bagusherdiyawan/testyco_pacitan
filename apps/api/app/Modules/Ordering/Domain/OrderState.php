<?php

namespace App\Modules\Ordering\Domain;

enum OrderState: string
{
    case Awaiting = 'awaiting_approval';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case Processing = 'processing';
    case Dispatched = 'dispatched';
    case Partial = 'partially_received';
    case Completed = 'completed';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    public function allows(string $action): bool
    {
        return match ($action) {
            'amend','approve','reject' => in_array($this, [self::Awaiting, self::Rejected], true),'cancel' => in_array($this, [self::Awaiting, self::Rejected, self::Approved, self::Processing], true),'process' => $this === self::Approved,'dispatch' => in_array($this, [self::Processing, self::Dispatched, self::Partial], true),'receive' => in_array($this, [self::Dispatched, self::Partial], true),default => false
        };
    }
}
