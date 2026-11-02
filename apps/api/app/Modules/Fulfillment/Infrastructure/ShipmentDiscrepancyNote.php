<?php

namespace App\Modules\Fulfillment\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class ShipmentDiscrepancyNote extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['case_version' => 'integer', 'created_at' => 'datetime'];
    }
}
