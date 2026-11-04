<?php

namespace App\Modules\Fulfillment\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class ReturnLine extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['pack_size' => 'integer', 'quantity_packs' => 'integer', 'received_packs' => 'integer', 'expires_on' => 'date:Y-m-d'];
    }
}
