<?php

namespace App\Modules\Materials\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class MaterialCount extends Model
{
    use HasUlids;

    protected $table = 'erp_material_counts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'stock_revision' => 'integer', 'counted_quantity' => 'integer', 'adjustment_value' => 'integer'];
    }
}
