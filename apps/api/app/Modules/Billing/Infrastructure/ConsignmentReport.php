<?php

namespace App\Modules\Billing\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class ConsignmentReport extends Model
{
    use HasUlids;

    protected $table = 'consignment_reports';

    protected $guarded = ['id'];

    public $timestamps = true;

    protected function casts(): array
    {
        return ['version' => 'integer', 'lines' => 'array', 'decided_at' => 'datetime'];
    }
}
