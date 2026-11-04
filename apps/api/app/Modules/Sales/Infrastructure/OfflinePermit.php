<?php

namespace App\Modules\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class OfflinePermit extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'access_version' => 'integer', 'issued_at' => 'datetime', 'expires_at' => 'datetime', 'closed_at' => 'datetime'];
    }
}
