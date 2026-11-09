<?php

namespace App\Modules\Procurement\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Purchase extends Model
{
    use HasUlids;

    protected $table = 'erp_purchases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'payments' => 'array', 'version' => 'integer', 'total_amount' => 'integer', 'received_amount' => 'integer', 'credited_amount' => 'integer', 'paid_amount' => 'integer', 'refunded_amount' => 'integer', 'ordered_on' => 'date:Y-m-d', 'due_on' => 'date:Y-m-d'];
    }
}
