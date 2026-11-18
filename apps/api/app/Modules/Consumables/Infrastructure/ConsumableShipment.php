<?php

namespace App\Modules\Consumables\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class ConsumableShipment extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function lines()
    {
        return $this->hasMany(ConsumableShipmentLine::class, 'shipment_id')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['version' => 'integer', 'dispatched_at' => 'datetime', 'received_at' => 'datetime'];
    }
}
