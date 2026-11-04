<?php

namespace App\Modules\Billing\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class InvoiceCredit extends Model
{
    use HasUlids;

    protected $table = 'invoice_credits';

    protected $guarded = ['id'];

    public $timestamps = true;

    protected function casts(): array
    {
        return ['version' => 'integer', 'base_amount' => 'integer', 'tax_amount' => 'integer', 'decided_at' => 'datetime'];
    }
}
