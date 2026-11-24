<?php

namespace App\Modules\Shifts\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class CashShift extends Model
{
    use HasUlids;

    protected $table = 'cash_shifts';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'noncash_sales' => 'integer',
            'noncash_refunds' => 'integer',
            'version' => 'integer',
            'opening_cash' => 'integer',
            'expected_opening_cash' => 'integer',
            'opening_discrepancy' => 'integer',
            'cash_sales' => 'integer',
            'cash_in' => 'integer',
            'cash_out' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'retained_cash' => 'integer',
            'deposit_cash' => 'integer',
            'difference_cash' => 'integer',
            'force_closed_by' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
