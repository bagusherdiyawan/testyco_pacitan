<?php

namespace App\Modules\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class SaleIntent extends Model
{
    use HasUlids;

    protected $table = 'sale_intents';

    protected $guarded = ['id'];

    public $timestamps = true;

    protected function casts(): array
    {
        return ['subtotal' => 'integer', 'discount_amount' => 'integer', 'voucher_snapshot' => 'array', 'items' => 'array', 'total_amount' => 'integer'];
    }
}
