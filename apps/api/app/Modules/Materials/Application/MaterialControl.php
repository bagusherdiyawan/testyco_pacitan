<?php

namespace App\Modules\Materials\Application;

use App\Models\User;
use App\Modules\Manufacturing\Application\ErpAccess;
use App\Modules\Materials\Domain\Quantity;
use App\Modules\Materials\Infrastructure\MaterialCount;
use App\Modules\Materials\Infrastructure\MaterialLot;

final class MaterialControl
{
    public function __construct(private MaterialLedger $ledger, private ErpAccess $access) {}

    public function transfer(User $u, string $org, array $v): array
    {
        $lot = MaterialLot::where('organization_id', $org)->lockForUpdate()->findOrFail($v['id']);
        $this->access->store($u, $org, $v['store_id']);
        $this->ledger->eligible($lot);
        abort_unless($lot->stock_revision === $v['version'] && $lot->store_id !== $v['store_id'], 409, 'Revisi/tujuan transfer tidak sesuai.');
        $quantity = Quantity::parse($v['quantity'], $lot->unit);
        abort_unless($quantity <= $lot->usable_quantity, 409);
        $value = $this->ledger->value($lot, $quantity);
        $target = $lot->replicate(['id', 'created_at', 'updated_at']);
        $target->store_id = $v['store_id'];
        $target->usable_quantity = 0;
        $target->quarantined_quantity = 0;
        $target->waste_quantity = 0;
        $target->remaining_value = 0;
        $target->stock_revision = 0;
        $target->provenance = [...$lot->provenance, 'source_lot_id' => $lot->id];
        $target->save();
        $this->ledger->apply($lot, $u->id, ['usable' => -$quantity], -$value, 'material_transfer', $target->id, $v['note']);
        // The receiving warehouse must inspect the physical arrival.
        $target = $this->ledger->apply($target, $u->id, ['quarantined' => $quantity], $value, 'material_transfer', $lot->id, $v['note']);

        return [...$target->toArray(), 'version' => $target->stock_revision, 'source_lot_id' => $lot->id];
    }

    public function propose(User $u, string $org, array $v): array
    {
        $lot = MaterialLot::where('organization_id', $org)->lockForUpdate()->findOrFail($v['id']);
        abort_unless($lot->stock_revision === $v['version'], 409, 'Revisi stok berubah.');

        return MaterialCount::create(['organization_id' => $org, 'material_lot_id' => $lot->id, 'bucket' => $v['bucket'], 'stock_revision' => $lot->stock_revision, 'counted_quantity' => Quantity::parse($v['quantity'], $lot->unit, true), 'adjustment_value' => $v['adjustment_value'], 'created_by' => $u->id, 'status' => 'pending', 'version' => 1, 'reason' => $v['note']])->toArray();
    }

    public function decide(User $u, string $org, array $v): array
    {
        $c = MaterialCount::where('organization_id', $org)->lockForUpdate()->findOrFail($v['id']);
        abort_unless($c->version === $v['version'] && $c->status === 'pending', 409);
        abort_if($c->created_by === $u->id, 403, 'Opname wajib ditinjau pengelola lain.');
        if ($v['decision'] === 'approve') {
            $lot = MaterialLot::where('organization_id', $org)->lockForUpdate()->findOrFail($c->material_lot_id);
            abort_unless($lot->stock_revision === $c->stock_revision, 409, 'Stok berubah; hitung ulang.');
            $delta = $c->counted_quantity - $lot->{$c->bucket.'_quantity'};
            abort_unless($delta !== 0 || $c->adjustment_value === 0, 422, 'Hitungan sama tidak boleh mengubah nilai.');
            if ($delta !== 0) {
                $this->ledger->apply($lot, $u->id, [$c->bucket => $delta], $delta > 0 ? $c->adjustment_value : -$c->adjustment_value, 'material_count', $c->id, $v['note']);
            }
        }
        $c->status = $v['decision'] === 'approve' ? 'approved' : 'rejected';
        $c->version++;
        $c->save();

        return $c->toArray();
    }
}
