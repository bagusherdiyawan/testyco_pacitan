<?php

namespace App\Modules\Inventory\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class InventoryBatch extends Model
{
    use HasUlids;

    protected $table = 'inventory_batches';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pack_size' => 'integer', 'usable_pieces' => 'integer', 'quarantined_pieces' => 'integer', 'expires_on' => 'date:Y-m-d'];
    }
}
