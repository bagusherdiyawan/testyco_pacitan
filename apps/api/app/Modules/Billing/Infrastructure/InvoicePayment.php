<?php

namespace App\Modules\Billing\Infrastructure;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class InvoicePayment extends Model
{
    use HasUlids;

    protected $table = 'invoice_payments';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_on' => 'date:Y-m-d', 'verified_at' => 'datetime', 'reversed_at' => 'datetime'];
    }
}
