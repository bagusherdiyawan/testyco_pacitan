<?php

namespace App\Modules\Inventory\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class InventoryProcess extends Model
{
    use HasUlids;

    protected $table = 'inventory_processes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['input_pieces' => 'integer', 'output_pieces' => 'integer', 'waste_pieces' => 'integer', 'pack_size' => 'integer', 'before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    public $timestamps = false;

    public function batch()
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }
}
