<?php

namespace App\Modules\Promotions\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Voucher extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'active' => 'boolean', 'value' => 'integer', 'maximum_discount' => 'integer', 'minimum_subtotal' => 'integer', 'usage_limit' => 'integer', 'used_count' => 'integer', 'store_ids' => 'array', 'product_ids' => 'array', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }
}
