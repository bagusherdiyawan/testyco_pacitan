<?php

namespace App\Modules\Shifts\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class CashMovementRequest extends Model
{
    use HasUlids;

    protected $table = 'cash_movement_requests';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'requested_by' => 'integer',
            'decided_by' => 'integer',
            'amount' => 'integer',
            'version' => 'integer',
            'created_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
