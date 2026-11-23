<?php

namespace App\Modules\Inventory\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class QuarantineRelease extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'store_id',
        'inventory_batch_id',
        'created_by',
        'reviewed_by',
        'status',
        'version',
        'snapshot_revision',
        'target_bucket',
        'quantity_pieces',
        'reason',
        'review_note',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot_revision' => 'integer',
            'quantity_pieces' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function batch()
    {
        return $this->belongsTo(InventoryBatch::class, 'inventory_batch_id');
    }
}
