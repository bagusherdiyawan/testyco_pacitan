<?php

namespace App\Modules\Identity\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class Store extends Model
{
    use HasUlids;

    protected $table = 'stores';

    protected $guarded = ['id'];

    protected $attributes = ['active' => true];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
