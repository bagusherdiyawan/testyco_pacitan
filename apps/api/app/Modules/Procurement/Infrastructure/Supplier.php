<?php

namespace App\Modules\Procurement\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Supplier extends Model
{
    use HasUlids;

    protected $table = 'erp_suppliers';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
