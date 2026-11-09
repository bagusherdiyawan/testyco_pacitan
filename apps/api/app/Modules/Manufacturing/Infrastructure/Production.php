<?php

namespace App\Modules\Manufacturing\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Production extends Model
{
    use HasUlids;

    protected $table = 'erp_productions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recipe_snapshot' => 'array', 'allocations' => 'array', 'costs' => 'array', 'version' => 'integer', 'runs' => 'integer', 'good_quantity' => 'integer', 'failed_quantity' => 'integer', 'good_packs' => 'integer', 'failed_pieces' => 'integer', 'produced_on' => 'date:Y-m-d', 'expires_on' => 'date:Y-m-d'];
    }
}
