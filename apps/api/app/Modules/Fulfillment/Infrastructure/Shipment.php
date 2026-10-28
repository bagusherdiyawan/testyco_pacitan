<?php

namespace App\Modules\Fulfillment\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Shipment extends Model
{
    use HasUlids;

    protected $table = 'shipments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['dispatched_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public $timestamps = false;

    public function lines()
    {
        return $this->hasMany(ShipmentLine::class);
    }
}
