<?php

namespace App\Modules\Consumables\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class ConsumableShipmentLine extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity_sent' => 'integer', 'quantity_received' => 'integer'];
    }
}
