<?php

namespace App\Modules\Inventory\Domain;

enum StockBucket: string
{
    case Sealed = 'usable';
    case Opened = 'opened';
    case Ready = 'ready';
    case Quarantine = 'quarantined';
    case Waste = 'waste';

    public function column(): string
    {
        return $this->value.'_pieces';
    }

    public function countable(): bool
    {
        return $this !== self::Waste;
    }
}
