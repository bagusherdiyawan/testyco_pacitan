<?php

namespace App\Modules\Manufacturing\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Recipe extends Model
{
    use HasUlids;

    protected $table = 'erp_recipes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['material_snapshot' => 'array', 'yield_quantity' => 'integer', 'ingredients' => 'array', 'product_snapshot' => 'array', 'version' => 'integer', 'output_packs' => 'integer', 'active' => 'boolean'];
    }
}
