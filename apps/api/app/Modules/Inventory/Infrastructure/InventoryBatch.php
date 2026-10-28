<?php

namespace App\Modules\Inventory\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class InventoryBatch extends Model
{
    use HasUlids;

    protected $table = 'inventory_batches';

    protected $guarded = ['id'];

    protected $appends = ['available_pieces'];

    public function getAvailablePiecesAttribute(): int
    {
        return $this->usable_pieces + $this->opened_pieces + $this->ready_pieces;
    }

    protected function casts(): array
    {
        return ['pack_size' => 'integer', 'usable_pieces' => 'integer', 'quarantined_pieces' => 'integer', 'opened_pieces' => 'integer', 'ready_pieces' => 'integer', 'waste_pieces' => 'integer', 'stock_revision' => 'integer', 'expires_on' => 'date:Y-m-d'];
    }
}
