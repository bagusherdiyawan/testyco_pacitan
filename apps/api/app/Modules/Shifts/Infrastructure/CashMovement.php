<?php

namespace App\Modules\Shifts\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class CashMovement extends Model
{
    use HasUlids;

    protected $table = 'cash_movements';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'created_at' => 'datetime'];
    }
}
