<?php

namespace App\Modules\Materials\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Material extends Model
{
    use HasUlids;

    protected $table = 'erp_materials';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'fefo_required' => 'boolean', 'expiry_required' => 'boolean', 'minimum_quantity' => 'integer', 'version' => 'integer'];
    }
}
