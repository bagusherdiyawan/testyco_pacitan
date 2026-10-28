<?php

namespace App\Modules\Catalog\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Product extends Model
{
    use HasUlids;

    protected $table = 'products';

    protected $guarded = ['id'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'pack_size' => 'integer', 'portion_size' => 'integer', 'wholesale_price' => 'integer', 'retail_price' => 'integer'];
    }
}
