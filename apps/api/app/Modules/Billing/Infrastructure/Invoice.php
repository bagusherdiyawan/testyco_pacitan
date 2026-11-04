<?php

namespace App\Modules\Billing\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model
{
    use HasUlids;

    protected $table = 'invoices';

    protected $guarded = ['id'];

    public $timestamps = true;

    protected function casts(): array
    {
        return ['version' => 'integer', 'lines' => 'array', 'subtotal' => 'integer', 'discount_amount' => 'integer', 'shipping_amount' => 'integer', 'tax_basis_points' => 'integer', 'tax_amount' => 'integer', 'total_amount' => 'integer', 'due_on' => 'date:Y-m-d', 'decided_at' => 'datetime'];
    }
}
