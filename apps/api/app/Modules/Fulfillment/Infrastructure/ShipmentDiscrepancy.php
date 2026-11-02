<?php

namespace App\Modules\Fulfillment\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class ShipmentDiscrepancy extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot' => 'array'];
    }
}
