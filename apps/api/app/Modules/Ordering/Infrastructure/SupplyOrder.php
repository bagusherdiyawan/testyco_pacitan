<?php

namespace App\Modules\Ordering\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class SupplyOrder extends Model
{
    use HasUlids;

    protected $table = 'supply_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'total_amount' => 'integer', 'approved_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(SupplyOrderLine::class);
    }
}
