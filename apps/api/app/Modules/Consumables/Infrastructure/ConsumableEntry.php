<?php

namespace App\Modules\Consumables\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class ConsumableEntry extends Model
{
    use HasUlids;

    protected $table = 'consumable_stock_entries';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['delta' => 'integer', 'amount' => 'integer', 'created_at' => 'datetime'];
    }
}
