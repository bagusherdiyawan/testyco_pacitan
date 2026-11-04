<?php

namespace App\Modules\Assets\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class AssetPlacement extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'handed_over_on' => 'date:Y-m-d', 'returned_on' => 'date:Y-m-d', 'confirmed_at' => 'datetime', 'return_confirmed_at' => 'datetime'];
    }
}
