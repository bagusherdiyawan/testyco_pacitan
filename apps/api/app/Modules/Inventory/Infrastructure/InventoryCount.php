<?php

namespace App\Modules\Inventory\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class InventoryCount extends Model
{
    use HasUlids;

    protected $table = 'inventory_counts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot_revision' => 'integer', 'expected_pieces' => 'integer', 'counted_pieces' => 'integer', 'difference_pieces' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function batch()
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }
}
