<?php

namespace App\Modules\Accounting\Application;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Shared\Domain\Proportion;
use Illuminate\Support\Facades\DB;

final class MissingCostSettlement
{
    public function command(User $user, string $org, string $action, array $v): array
    {
        $seed = DB::table('accounting_missing_costs')->where(fn ($q) => $q->where('owner_organization_id', $org)->orWhere('counterparty_organization_id', $org))->where('id', $v['id'])->firstOrFail();
        $order = SupplyOrder::whereKey($seed->order_id)->lockForUpdate()->firstOrFail();
        $organizations = Organization::whereIn('id', [$order->organization_id, $order->supplier_organization_id])->orderBy('id')->lockForUpdate()->get();
        abort_unless($organizations->count() === 2 && $organizations->every(fn ($o) => $o->active), 409, 'Kedua organisasi wajib aktif.');
        app(AccountingAccess::class)->manage($user, $org);
        $r = DB::table('accounting_missing_costs')->where('id', $seed->id)->lockForUpdate()->firstOrFail();
        abort_unless($r->version === $v['version'], 409, 'Versi kesepakatan biaya hilang berubah.');
        abort_unless(DB::table('shipment_discrepancies')->where('supply_order_id', $order->id)->where('shipment_id', $r->shipment_id)->exists(), 409, 'Laporkan kasus selisih dahulu sebelum menyepakati biaya hilang.');
        $costing = app(DistributionCosting::class);
        $source = DB::table('inventory_cost_entries')->where('organization_id', $r->owner_organization_id)->where('id', $r->source_entry_id)->firstOrFail();
        $event = $costing->review($source);
        abort_unless($event && $event->status === 'review_required' && $source->value_delta !== null && -(int) $source->quantity_delta === (int) $r->sent_pieces && -(int) $source->value_delta - Proportion::floor(-(int) $source->value_delta, (int) $r->received_pieces, (int) $r->sent_pieces) === (int) $r->missing_value, 409, 'Bukti sumber hilang berubah/sudah direkonsiliasi; tidak boleh dibebankan dua kali.');
        if ($action === 'missing_propose') {
            abort_unless($org === $order->supplier_organization_id, 403, 'Hanya owner/admin pemasok yang mengusulkan kesepakatan.');
            abort_unless(in_array($r->status, ['awaiting_agreement', 'rejected'], true), 409);
            $owner = $costing->policy($r->owner_organization_id);
            $other = $costing->policy($r->counterparty_organization_id);
            $old = $costing->policy($r->owner_organization_id, $event->policy_id);
            abort_unless($owner && $old && $costing->matching($owner, $old, ['finished', 'loss']), 409, 'Pemetaan sumber/persediaan tidak cocok.');
            $claim = (int) $r->missing_value - Proportion::floor((int) $r->missing_value, $v['owner_share_basis_points'], 10000);
            if ($claim > 0) {
                $a = json_decode($owner->mapping, true);
                $b = $other ? json_decode($other->mapping, true) : [];
                abort_unless(isset($a['trade_receivable'], $b['trade_payable'], $b['loss']), 409, 'Aktifkan pemetaan wholesale kedua pihak untuk klaim antar organisasi.');
            }
            $new = ['status' => 'proposed', 'owner_share_basis_points' => $v['owner_share_basis_points'], 'policy_id' => $owner->id, 'counterparty_policy_id' => $other?->id, 'proposed_by' => $user->id, 'note' => $v['note'], 'version' => $r->version + 1, 'updated_at' => now()];
        } else {
            abort_unless($org === $order->organization_id && $org !== $order->supplier_organization_id, 403, 'Keputusan wajib pimpinan mitra penerima.');
            abort_unless($r->status === 'proposed' && $r->proposed_by !== $user->id && ($v['consent'] ?? false) === true, 409, 'Periksa persetujuan dan pengusul yang berbeda.');
            $new = ['status' => $v['decision'] === 'approve' ? 'approved' : 'rejected', 'decided_by' => $user->id, 'decision_note' => $v['note'], 'version' => $r->version + 1, 'updated_at' => now()];
            if ($v['decision'] === 'approve') {
                $day = now('Asia/Jakarta')->format('Y-m-d');
                $ownerPolicy = $costing->policy($r->owner_organization_id, $r->policy_id);
                $old = $costing->policy($r->owner_organization_id, $event->policy_id);
                abort_unless($ownerPolicy && $old && $costing->matching($ownerPolicy, $old, ['finished', 'loss']), 409);
                $own = Proportion::floor((int) $r->missing_value, (int) $r->owner_share_basis_points, 10000);
                $claim = (int) $r->missing_value - $own;
                $costing->open($r->owner_organization_id, $event->event_on);
                $costing->open($r->owner_organization_id, $day);
                $terms = [['loss', 'finished', $own]];
                if ($claim > 0) {
                    $costing->open($r->counterparty_organization_id, $day);
                    $other = $costing->policy($r->counterparty_organization_id, $r->counterparty_policy_id);
                    abort_unless($other && isset(json_decode($other->mapping, true)['trade_payable']), 409);
                    $terms[] = ['trade_receivable', 'finished', $claim];
                    app(AutomaticPosting::class)->record($r->counterparty_organization_id, $user->id, 'distribution_missing', $r->id, $day, [['loss', 'trade_payable', $claim]], 'Klaim biaya hilang disetujui pusat dan pimpinan mitra '.$r->id, null, $other->id);
                }
                app(AutomaticPosting::class)->record($r->owner_organization_id, $user->id, 'distribution_missing', $r->id, $day, $terms, 'Sisa hilang Rp'.$r->missing_value.'; porsi pemilik '.$r->owner_share_basis_points.'/10000, klaim Rp'.$claim.'; persetujuan dua pihak '.$r->id, null, $r->policy_id);
            }
        }
        DB::table('accounting_missing_costs')->where('id', $r->id)->update($new);
        if ($new['status'] === 'approved') {
            $costing->maybeCover($source);
            $costing->wake($order->id, 'Barang retur tidak diterima');
        }
        $result = ['id' => $r->id, 'organization_id' => $org, 'version' => $new['version'], 'status' => $new['status'], 'missing_value' => (int) $r->missing_value, 'owner_share_basis_points' => $new['owner_share_basis_points'] ?? $r->owner_share_basis_points];
        foreach ([$order->organization_id, $order->supplier_organization_id] as $scope) {
            Audit::record($user->id, $scope, 'accounting.'.$action, $r->id, null, [...$result, 'order_id' => $order->id, 'operation_id' => $v['operation_id'], 'note' => $v['note']]);
        }

        return $result;
    }
}
