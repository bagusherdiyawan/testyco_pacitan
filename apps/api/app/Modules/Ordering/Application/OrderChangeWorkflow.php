<?php

namespace App\Modules\Ordering\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Ordering\Infrastructure\OrderChange;
use App\Modules\Ordering\Infrastructure\SupplyOrder;
use App\Shared\Application\Operations;
use Illuminate\Support\Facades\DB;

final class OrderChangeWorkflow
{
    public function __construct(private OrderAccess $access, private Access $identity, private OrderWorkflow $book, private Operations $operations) {}

    private function supplier(User $user, SupplyOrder $order): void
    {
        $this->access->supplier($user, $order);
        abort_unless(in_array($this->identity->role($user, $order->supplier_organization_id)?->value, ['owner', 'admin'], true), 403);
    }

    private function eligible(SupplyOrder $order): bool
    {
        return in_array($order->status, ['approved', 'processing'], true) && ! DB::table('shipments')->where('supply_order_id', $order->id)->exists();
    }

    private function snapshot(SupplyOrder $order): array
    {
        $lines = $order->lines->sortBy('id')->values()->map(fn ($line) => $line->only(['id', 'supplier_product_id', 'local_product_id', 'sku', 'name', 'local_sku', 'local_name', 'condition', 'pack_size', 'quantity_packs', 'unit_price']))->all();

        return ['lines' => $lines, 'total_amount' => $order->total_amount];
    }

    private function audit(User $user, SupplyOrder $order, string $event, OrderChange $change): void
    {
        foreach (array_unique([$order->organization_id, $order->supplier_organization_id]) as $organization) {
            Audit::record($user->id, $organization, 'order.change_'.$event, $order->id, null, ['change_id' => $change->id, 'status' => $change->status, 'version' => $order->version, 'before' => $change->before, 'after' => $change->after, 'reason' => $change->reason, 'decision_note' => $change->decision_note]);
        }
    }

    public function context(User $user, string $id): array
    {
        $order = SupplyOrder::with('lines')->findOrFail($id);
        $this->access->view($user, $order);
        $pending = OrderChange::pending($id);
        $supplier = in_array($this->identity->role($user, $order->supplier_organization_id)?->value, ['owner', 'admin'], true);
        $operating = Store::whereKey($order->store_id)->where('active', true)->exists() && Organization::whereKey($order->organization_id)->where('active', true)->exists();
        $decide = $operating && in_array($this->identity->role($user, $order->organization_id)?->value, $order->kind === 'internal_transfer' ? ['owner', 'admin'] : ['partner_lead'], true);
        $supplierActive = Organization::whereKey($order->supplier_organization_id)->where('active', true)->exists();

        return [...OrderChange::where('supply_order_id', $id)->orderByDesc('id')->paginate(50)->toArray(), 'order' => $order->toArray(), 'actions' => ['propose' => $supplier && $operating && ! $pending && $this->eligible($order), 'approve' => $pending && $decide && $supplierActive, 'reject' => $pending && $decide, 'withdraw' => $pending && $supplier], 'change_pending' => $pending];
    }

    public function propose(User $user, string $id, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        $this->supplier($user, $initial);

        return $this->operations->run($initial->supplier_organization_id, $user->id, $v['operation_id'], 'order.change_propose:'.$id, $v, function () use ($user, $id, $v) {
            $order = $this->book->lock($id);
            abort_unless($order->version === (int) $v['version'] && $this->eligible($order) && ! OrderChange::pending($id), 409, 'Pesanan berubah, sudah dikirim atau masih memiliki usulan menunggu keputusan.');
            $this->access->targetActive($order);
            $before = $this->snapshot($order);
            $requested = collect($v['lines'])->keyBy('order_line_id');
            abort_unless(count($v['lines']) === count($before['lines']) && $requested->count() === count($before['lines']), 422, 'Isi setiap baris pesanan tepat sekali.');
            $after = $before;
            $reduced = false;
            foreach ($after['lines'] as &$line) {
                $input = $requested->get($line['id']);
                abort_unless($input && (int) $input['quantity_packs'] >= 1 && (int) $input['quantity_packs'] <= $line['quantity_packs'], 422, 'Usulan hanya boleh mengurangi jumlah baris yang ada, minimal satu kemasan.');
                $reduced = $reduced || (int) $input['quantity_packs'] < $line['quantity_packs'];
                $line['quantity_packs'] = (int) $input['quantity_packs'];
            }
            unset($line);
            abort_unless($reduced, 422, 'Setidaknya satu baris harus berkurang.');
            $after['total_amount'] = array_sum(array_map(fn ($line) => $line['quantity_packs'] * $line['unit_price'], $after['lines']));
            $change = OrderChange::create(['supply_order_id' => $id, 'pending_order_id' => $id, 'base_version' => $order->version, 'pending_version' => $order->version + 1, 'status' => 'pending', 'requested_by' => $user->id, 'reason' => $v['reason'], 'before' => [...$before, 'approved_by' => $order->approved_by, 'approved_at' => $order->approved_at?->toIso8601String()], 'after' => $after]);
            $order->version++;
            $order->save();
            $this->audit($user, $order, 'proposed', $change);

            return ['order' => $order->toArray(), 'change' => $change->toArray()];
        });
    }

    public function decide(User $user, string $id, string $changeId, string $action, array $v): array
    {
        $initial = SupplyOrder::findOrFail($id);
        if ($action === 'withdraw') {
            $this->supplier($user, $initial);
        } else {
            $this->access->approver($user, $initial);
        }
        $org = $action === 'withdraw' ? $initial->supplier_organization_id : $initial->organization_id;

        return $this->operations->run($org, $user->id, $v['operation_id'], 'order.change_'.$action.':'.$id.':'.$changeId, $v, function () use ($user, $id, $changeId, $action, $v) {
            $order = $this->book->lock($id);
            $change = OrderChange::where('supply_order_id', $id)->lockForUpdate()->findOrFail($changeId);
            abort_unless($change->status === 'pending' && $change->pending_order_id === $id && $change->pending_version === $order->version && $order->version === (int) $v['version'] && $this->eligible($order), 409, 'Usulan atau versi pesanan berubah. Muat ulang dan tinjau kembali.');
            if ($action === 'approve') {
                $this->access->targetActive($order);
                abort_unless(Organization::whereKey($order->supplier_organization_id)->where('active', true)->exists(), 409, 'Organisasi pemasok nonaktif.');
                abort_unless($this->snapshot($order) === array_intersect_key($change->before, array_flip(['lines', 'total_amount'])), 409, 'Snapshot pesanan berubah; usulan tidak dapat diterapkan.');
                foreach ($change->after['lines'] as $line) {
                    $order->lines()->whereKey($line['id'])->update(['quantity_packs' => $line['quantity_packs']]);
                }
                $order->fill(['total_amount' => $change->after['total_amount'], 'approved_by' => $user->id, 'approved_at' => now()]);
            }
            $order->version++;
            $order->save();
            $change->update(['pending_order_id' => null, 'status' => ['approve' => 'approved', 'reject' => 'rejected', 'withdraw' => 'withdrawn'][$action], 'decided_by' => $user->id, 'decision_note' => $v['reason'] ?? null, 'decided_at' => now()]);
            $order->load('lines');
            $this->audit($user, $order, $action, $change);

            return ['order' => $order->toArray(), 'change' => $change->toArray()];
        });
    }
}
