<?php

namespace App\Modules\Billing\Application;

use Illuminate\Support\Facades\DB;

/** Billing-owned bulk projection. Caller must authorize invoice IDs before requesting it. */
final class CommercialBalances
{
    public function forInvoices(array $ids): array
    {
        $credits = DB::table('invoice_credits')->whereIn('invoice_id', $ids)->where('status', 'accepted')->selectRaw('invoice_id,SUM(base_amount) as base_amount,SUM(tax_amount) as tax_amount')->groupBy('invoice_id')->get()->keyBy('invoice_id');
        $payments = DB::table('invoice_payments')->whereIn('invoice_id', $ids)->where('status', 'verified')->selectRaw("invoice_id,SUM(CASE WHEN kind='collection' THEN amount ELSE 0 END) as collected,SUM(CASE WHEN kind='credit_refund' THEN amount ELSE 0 END) as refunded")->groupBy('invoice_id')->get()->keyBy('invoice_id');
        $rows = [];
        foreach ($ids as $id) {
            $c = $credits->get($id);
            $p = $payments->get($id);
            $rows[$id] = ['base_credit' => (int) ($c?->base_amount ?? 0), 'credit_amount' => (int) ($c?->base_amount ?? 0) + (int) ($c?->tax_amount ?? 0), 'collected_amount' => (int) ($p?->collected ?? 0), 'refunded_amount' => (int) ($p?->refunded ?? 0)];
        }

        return $rows;
    }
}
