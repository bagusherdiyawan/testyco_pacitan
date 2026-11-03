<?php

namespace App\Modules\Fulfillment\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class ReplacementShipment extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'dispatched_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(ReplacementLine::class);
    }
}
