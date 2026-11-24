<?php

namespace App\Modules\Consumables\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class ConsumableItem extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'version' => 'integer'];
    }
}
