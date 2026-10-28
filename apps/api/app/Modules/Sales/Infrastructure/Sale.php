<?php

namespace App\Modules\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Sale extends Model
{
    use HasUlids;

    protected $table = 'sales';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function lines()
    {
        return $this->hasMany(SaleLine::class)->with('allocations');
    }

    protected function casts(): array
    {
        return ['subtotal' => 'integer', 'discount_amount' => 'integer', 'total_amount' => 'integer', 'cash_received' => 'integer', 'change_amount' => 'integer', 'sold_at' => 'datetime'];
    }
}
