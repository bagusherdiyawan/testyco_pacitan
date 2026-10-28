<?php

namespace App\Modules\Fulfillment\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class ShipmentLine extends Model
{
    use HasUlids;

    protected $table = 'shipment_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity_packs' => 'integer', 'good_packs' => 'integer', 'damaged_packs' => 'integer', 'pack_size' => 'integer', 'expires_on' => 'date:Y-m-d'];
    }

    public $timestamps = false;
}
