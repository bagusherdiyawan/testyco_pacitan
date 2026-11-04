<?php

namespace App\Modules\Assets\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class AssetIncident extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'owner_cost' => 'integer', 'recipient_cost' => 'integer', 'reported_on' => 'date:Y-m-d', 'completed_on' => 'date:Y-m-d'];
    }
}
