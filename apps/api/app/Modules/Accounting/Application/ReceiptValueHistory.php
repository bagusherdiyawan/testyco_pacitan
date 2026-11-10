<?php

namespace App\Modules\Accounting\Application;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Resolve immutable price layers, including older dedicated-batch proofs, without migrating history. */
final class ReceiptValueHistory
{
    public function read(string $org, string $batch, Collection $entries, array $mapping): array
    {
        $receipts = $entries->where('source_type', 'shipment_receipt')->where('quantity_delta', '>', 0)->keyBy('id');
        $events = DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'distribution_receipt')->whereIn('source_id', $receipts->pluck('source_id')->all())->limit(10001)->get()->keyBy('source_id');
        $policies = DB::table('accounting_policies')->where('organization_id', $org)->whereIn('id', $events->pluck('policy_id')->unique()->all())->limit(1001)->get()->keyBy('id');
        $variances = DB::table('inventory_invoice_variances as v')->join('accounting_invoice_revaluations as r', 'r.id', '=', 'v.revaluation_id')->where('v.organization_id', $org)->where('r.organization_id', $org)->where('v.inventory_batch_id', $batch)->where('r.status', 'applied')->orderBy('v.stock_revision')->orderBy('v.id')->limit(10001)->get(['v.*']);
        $layers = DB::table('inventory_invoice_receipt_adjustments')->where('organization_id', $org)->whereIn('variance_id', $variances->pluck('id')->all())->orderBy('id')->limit(10001)->get();
        $bytes = strlen(json_encode([$events, $policies, $variances, $layers]));
        $this->require($events->count() <= 10000 && $policies->count() <= 1000 && $variances->count() <= 10000 && $layers->count() <= 10000 && $bytes <= 8 * 1024 * 1024, 'Bukti nilai penerimaan melampaui batas; perlu rekonsiliasi terperinci.');
        $prices = [];
        foreach ($receipts as $e) {
            $event = $events->get($e->source_id);
            $policy = $event ? $policies->get($event->policy_id) : null;
            $this->require($e->value_delta !== null && (int) $e->value_delta >= 0 && $event && in_array($event->status, ['pending', 'posted'], true) && $policy && $policy->activated_at, 'Penerimaan campuran tidak mempunyai bukti putus beli/nilai/pemetaan yang sah.');
            $m = json_decode($policy->mapping, true);
            foreach (['finished', 'cogs', 'loss'] as $role) {
                $this->require(isset($m[$role], $mapping[$role]) && $m[$role]['account_id'] === $mapping[$role]['account_id'], 'Akun biaya penerimaan campuran berbeda; perlu rekonsiliasi pemetaan.');
            }
            $prices[$e->id] = (int) $e->value_delta;
        }
        foreach ($variances as $v) {
            $proofs = $layers->where('variance_id', $v->id);
            if ($proofs->isEmpty()) {
                // Legacy ADR054 had exactly one receipt for this shipment line.
                $r = $receipts->where('source_id', $v->shipment_line_id);
                $this->require($r->count() === 1, 'Bukti variance lama tidak mempunyai satu penerimaan asal.');
                $id = $r->first()->id;
                $this->require($prices[$id] === (int) $v->original_value, 'Dasar koreksi lama tidak konservatif.');
                $prices[$id] = (int) $v->final_value;

                continue;
            }
            $before = $after = 0;
            foreach ($proofs as $proof) {
                $this->require(isset($prices[$proof->cost_entry_id]) && $prices[$proof->cost_entry_id] === (int) $proof->before_value && (int) $proof->after_value >= 0 && (int) $proof->after_value - (int) $proof->before_value === (int) $proof->value_delta, 'Lapisan harga penerimaan tidak konservatif.');
                $prices[$proof->cost_entry_id] = (int) $proof->after_value;
                $before += (int) $proof->before_value;
                $after += (int) $proof->after_value;
            }
            $this->require($before === (int) $v->original_value && $after === (int) $v->final_value, 'Jumlah bukti penerimaan tidak sesuai variance.');
        }

        return ['prices' => $prices, 'variances' => $variances->keyBy('id'), 'evidence_bytes' => $bytes];
    }

    private function require(bool $ok, string $message): void
    {
        if (! $ok) {
            throw new DomainException($message);
        }
    }
}
