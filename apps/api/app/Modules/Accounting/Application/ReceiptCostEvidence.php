<?php

namespace App\Modules\Accounting\Application;

use DomainException;
use Illuminate\Support\Facades\DB;

final class ReceiptCostEvidence
{
    public function hasOwnedReceipt(string $order): bool
    {
        return DB::table('accounting_receipt_costs as c')->join('shipment_lines as l', 'l.id', '=', 'c.shipment_line_id')->join('shipments as s', 's.id', '=', 'l.shipment_id')->join('supply_orders as o', 'o.id', '=', 's.supply_order_id')->where('o.id', $order)->whereColumn('c.organization_id', 'o.organization_id')->where(fn ($q) => $q->where('c.good_pieces', '>', 0)->orWhere('c.quarantined_pieces', '>', 0))->exists();
    }

    public function read(string $org, array $lines): array
    {
        $events = DB::table('accounting_events')->where('organization_id', $org)->where('source_type', 'distribution_receipt')->whereIn('source_id', $lines)->limit(10001)->get()->keyBy('source_id');
        $proofs = DB::table('accounting_receipt_costs')->where('organization_id', $org)->whereIn('shipment_line_id', $lines)->limit(10001)->get()->keyBy('shipment_line_id');
        $bytes = strlen(json_encode([$events, $proofs]));
        if ($events->count() > 10000 || $proofs->count() > 10000 || $bytes > 8 * 1024 * 1024) {
            throw new DomainException('Bukti penerimaan melampaui10.000/8MiB.');
        }
        $out = [];
        foreach (array_unique($lines) as $line) {
            $event = $events->get($line);
            $proof = $proofs->get($line);
            if ($event) {
                $terms = json_decode($event->terms, true);
                $value = 0;
                $validTerms = is_array($terms);
                foreach (is_array($terms) ? $terms : [] as $term) {
                    if (! is_array($term) || count($term) !== 3 || $term[0] !== 'finished' || $term[1] !== 'purchase_clearing' || ! is_int($term[2]) || $term[2] < 0) {
                        $validTerms = false;
                    } else {
                        $value += $term[2];
                    }
                }
                $trusted = in_array($event->status, ['pending', 'posted'], true) && $validTerms && $value > 0 && (! $proof || ($proof->policy_id === $event->policy_id && $proof->received_on === $event->event_on && (int) $proof->quoted_value === $value));
                $out[$line] = (object) ['policy_id' => $event->policy_id, 'event_on' => $event->event_on, 'trusted' => $trusted];
            } elseif ($proof && (int) $proof->quoted_value === 0) {
                // No nominal GL event is needed for a known zero-value receipt.
                $out[$line] = (object) ['policy_id' => $proof->policy_id, 'event_on' => $proof->received_on, 'trusted' => true];
            }
        }

        return ['origins' => $out, 'proofs' => $proofs, 'evidence_bytes' => $bytes];
    }
}
