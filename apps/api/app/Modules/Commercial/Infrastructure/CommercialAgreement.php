<?php

namespace App\Modules\Commercial\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class CommercialAgreement extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['terms' => 'array', 'version' => 'integer', 'budget_spent' => 'integer', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'activated_at' => 'datetime', 'stopped_at' => 'datetime'];
    }
}
