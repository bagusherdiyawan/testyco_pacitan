<?php

namespace App\Modules\Quality\Application;

use App\Models\User;
use App\Modules\Accounting\Application\AutomaticPosting;
use App\Modules\Inventory\Application\StockLedger;
use App\Modules\Inventory\Infrastructure\InventoryBatch;
use App\Modules\Manufacturing\Infrastructure\Production;
use App\Modules\Materials\Application\MaterialLedger;
use App\Modules\Materials\Domain\Quantity;
use App\Modules\Materials\Infrastructure\MaterialLot;
use App\Modules\Procurement\Infrastructure\Purchase;

final class Inspections
{
    public function __construct(private MaterialLedger $materials, private StockLedger $stock) {}

    public function material(User $u, string $org, array $v): array
    {
        $lot = MaterialLot::where('organization_id', $org)->lockForUpdate()->findOrFail($v['id']);
        abort_unless($lot->stock_revision === $v['version'], 409, 'Versi lot berubah.');
        abort_if(in_array($v['decision'], ['release', 'reject', 'return', 'hold', 'unhold'], true) && Production::where('output_material_lot_id', $lot->id)->where('status', 'awaiting_quality')->exists(), 409, 'Periksa hasil melalui keputusan pemeriksaan produksi.');
        if (in_array($v['decision'], ['hold', 'unhold'], true)) {
            $lot->blocked = $v['decision'] === 'hold';
            $lot->stock_revision++;
            $lot->evidence = $v['note'];
            $lot->save();
        } else {
            $quantity = Quantity::parse($v['quantity'], $lot->unit);
            $value = 0;
            if ($v['decision'] === 'release') {
                $this->materials->eligible($lot);
                abort_unless($quantity <= $lot->quarantined_quantity, 409);
                $changes = ['quarantined' => -$quantity, 'usable' => $quantity];
            } else {
                $bucket = $v['decision'] === 'return' ? $v['bucket'] : 'quarantined';
                abort_unless(in_array($bucket, ['usable', 'quarantined'], true) && $quantity <= $lot->{$bucket.'_quantity'}, 409);
                $value = $this->materials->value($lot, $quantity);
                $changes = [$bucket => -$quantity];
                if ($v['decision'] === 'reject') {
                    $changes['waste'] = $quantity;
                }
                if ($v['decision'] === 'return') {
                    abort_unless($lot->purchase_id && $v['verified'] === true, 422, 'Retur wajib pembelian asal dan bukti terverifikasi.');
                    abort_unless($v['supplier_credit'] <= $value, 422, 'Kredit retur melebihi nilai historis bahan yang benar-benar dikembalikan.');
                    $p = Purchase::where('organization_id', $org)->lockForUpdate()->findOrFail($lot->purchase_id);
                    abort_unless($v['supplier_credit'] <= $p->received_amount - $p->credited_amount, 409, 'Kredit supplier melebihi nilai pembelian diterima yang belum dikreditkan.');
                    $p->credited_amount += $v['supplier_credit'];
                    $p->version++;
                    $p->save();
                }
            }
            if ($v['decision'] === 'return') {
                app(AutomaticPosting::class)->record($org, $u->id, 'supplier_return', $lot->id.':'.($lot->stock_revision + 1), now('Asia/Jakarta')->format('Y-m-d'), [['payable', 'raw', $v['supplier_credit']], ['loss', 'raw', $value - $v['supplier_credit']]], $v['note']);
            }
            $lot = $this->materials->apply($lot, $u->id, $changes, -$value, 'material_'.$v['decision'], $lot->id, $v['note']);
        }

        return [...$lot->toArray(), 'version' => $lot->stock_revision];
    }

    public function production(User $u, string $org, array $v): array
    {
        $p = Production::where('organization_id', $org)->lockForUpdate()->findOrFail($v['id']);
        abort_unless($p->version === $v['version'] && $p->status === 'awaiting_quality', 409, 'Hasil sudah diperiksa/versi berubah.');
        if ($v['decision'] === 'release') {
            abort_unless($p->expires_on->format('Y-m-d') >= now('Asia/Jakarta')->format('Y-m-d'), 409, 'Hasil sudah kedaluwarsa.');
            foreach ($p->allocations as $a) {
                $this->materials->eligible(MaterialLot::where('organization_id', $org)->findOrFail($a['lot_id']));
            }
        }
        if ($p->output_material_lot_id) {
            $batch = MaterialLot::where('organization_id', $org)->where('store_id', $p->store_id)->lockForUpdate()->findOrFail($p->output_material_lot_id);
            abort_unless($batch->stock_revision === $v['stock_revision'] && $batch->quarantined_quantity >= $p->good_quantity, 409, 'Stok karantina hasil berubah.');
            $value = $v['decision'] === 'release' ? 0 : $this->materials->value($batch, $p->good_quantity);
            $this->materials->apply($batch, $u->id, ['quarantined' => -$p->good_quantity, ($v['decision'] === 'release' ? 'usable' : 'waste') => $p->good_quantity], -$value, 'production_quality', $p->id, $v['note']);
        } else {
            $batch = InventoryBatch::where('organization_id', $org)->where('store_id', $p->store_id)->lockForUpdate()->findOrFail($p->output_batch_id);
            $pieces = $p->good_packs * $p->recipe_snapshot['product_snapshot']['pack_size'];
            abort_unless($batch->stock_revision === $v['stock_revision'] && $batch->quarantined_pieces >= $pieces, 409, 'Stok karantina hasil berubah.');
            $this->stock->apply($batch, $u->id, ['quarantined' => -$pieces, ($v['decision'] === 'release' ? 'usable' : 'waste') => $pieces], 'production_quality', $p->id, $v['note']);
        }
        $p->status = $v['decision'] === 'release' ? 'released' : 'rejected';
        $p->inspection_note = $v['note'];
        $p->inspected_by = $u->id;
        $p->version++;
        $p->save();

        return $p->toArray();
    }
}
