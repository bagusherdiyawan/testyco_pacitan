<?php

namespace App\Modules\Materials\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class MaterialLot extends Model
{
    use HasUlids;

    protected $table = 'erp_material_lots';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['usable_quantity' => 'integer', 'quarantined_quantity' => 'integer', 'waste_quantity' => 'integer', 'remaining_value' => 'integer', 'stock_revision' => 'integer', 'blocked' => 'boolean', 'provenance' => 'array', 'received_on' => 'date:Y-m-d', 'expires_on' => 'date:Y-m-d'];
    }
}
