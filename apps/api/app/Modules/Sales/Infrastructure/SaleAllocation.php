<?php

namespace App\Modules\Sales\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class SaleAllocation extends Model
{
    use HasUlids;

    protected $table = 'sale_allocations';

    protected $guarded = ['id'];

    protected $hidden = ['commercial_origin_snapshot'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['commercial_origin_snapshot' => 'array', 'pieces' => 'integer', 'batch_pack_size' => 'integer', 'expires_on' => 'date:Y-m-d'];
    }
}
