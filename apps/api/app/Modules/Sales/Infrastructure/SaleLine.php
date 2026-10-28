<?php

namespace App\Modules\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class SaleLine extends Model
{
    use HasUlids;

    protected $table = 'sale_lines';

    protected $guarded = ['id'];

    public $timestamps = false;

    public function allocations()
    {
        return $this->hasMany(SaleAllocation::class);
    }

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'pieces_per_unit' => 'integer', 'unit_price' => 'integer', 'line_total' => 'integer'];
    }
}
