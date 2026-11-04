<?php

namespace App\Modules\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class OfflineSale extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'snapshot' => 'array', 'version' => 'integer', 'captured_at' => 'datetime', 'received_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }
}
