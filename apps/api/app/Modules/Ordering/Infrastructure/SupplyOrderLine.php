<?php

namespace App\Modules\Ordering\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class SupplyOrderLine extends Model
{
    use HasUlids;

    protected $table = 'supply_order_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pack_size' => 'integer', 'quantity_packs' => 'integer', 'unit_price' => 'integer'];
    }

    public $timestamps = false;
}
