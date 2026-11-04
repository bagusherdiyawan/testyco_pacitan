<?php

namespace App\Modules\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class SaleRefund extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'lines' => 'array', 'refunded_at' => 'datetime'];
    }
}
