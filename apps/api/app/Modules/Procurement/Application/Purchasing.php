<?php

namespace App\Modules\Procurement\Application;

use App\Models\User;
use App\Modules\Accounting\Application\AutomaticPosting;
use App\Modules\Manufacturing\Application\ErpAccess;
use App\Modules\Materials\Application\MaterialLedger;
use App\Modules\Materials\Domain\Quantity;
use App\Modules\Materials\Infrastructure\Material;
use App\Modules\Materials\Infrastructure\MaterialLot;
use App\Modules\Procurement\Infrastructure\Purchase;
use App\Modules\Procurement\Infrastructure\Supplier;
use App\Shared\Domain\Proportion;
use App\Shared\Domain\Rupiah;
use Illuminate\Support\Str;

final class Purchasing
{
    public function __construct(private ErpAccess $access, private MaterialLedger $ledger) {}

    public function create(User $u, string $org, array $v): array
    {
        $this->access->store($u, $org, $v['store_id']);
        Supplier::where('organization_id', $org)->findOrFail($v['supplier_id']);
        abort_if(Purchase::where('organization_id', $org)->where('number', $v['number'])->exists(), 409, 'Nomor pembelian sudah digunakan.');
        $lines = [];
        $total = 0;
        foreach ($v['quantities'] as $id => $input) {
            if ($input === null || $input === '') {
                continue;
            }
            $m = Material::where('organization_id', $org)->where('active', true)->findOrFail($id);
            $quantity = Quantity::parse($input, $m->unit);
            $amount = $v['amounts'][$id] ?? null;
            abort_unless(is_int($amount) && $amount >= 0 && $amount <= Rupiah::LIMIT, 422, 'Isi total harga per baris secara eksplisit.');
            $total = Rupiah::add($total, $amount);
            $lines[$id] = ['material_id' => $id, 'name' => $m->name, 'unit' => $m->unit, 'quantity' => $quantity, 'received_quantity' => 0, 'amount' => $amount, 'received_amount' => 0];
        }
        abort_unless(count($lines) > 0 && $v['due_on'] >= $v['ordered_on'], 422, 'Pembelian kosong atau tanggal tidak valid.');

        return Purchase::create(['organization_id' => $org, 'store_id' => $v['store_id'], 'supplier_id' => $v['supplier_id'], 'number' => $v['number'], 'status' => 'draft', 'ordered_on' => $v['ordered_on'], 'due_on' => $v['due_on'], 'lines' => $lines, 'payments' => [], 'total_amount' => $total, 'received_amount' => 0, 'paid_amount' => 0, 'refunded_amount' => 0, 'credited_amount' => 0, 'version' => 1, 'created_by' => $u->id, 'note' => $v['note']])->toArray();
    }

    public function decide(string $org, array $v): array
    {
        $p = Purchase::where('organization_id', $org)->lockForUpdate()->findOrFail($v['id']);
        abort_unless($p->version === $v['version'] && ($v['decision'] === 'approve' ? $p->status === 'draft' : in_array($p->status, ['draft', 'approved'], true) && $p->received_amount === 0 && ! MaterialLot::where('purchase_id', $p->id)->exists()), 409, 'Versi/status pembelian berubah atau sudah diterima.');
        $p->status = $v['decision'] === 'approve' ? 'approved' : 'cancelled';
        $p->version++;
        $p->note = $v['note'];
        $p->save();

        return $p->toArray();
    }

    public function receive(User $u, string $org, array $v): array
    {
        $m = Material::where('organization_id', $org)->where('active', true)->findOrFail($v['material_id']);
        $this->access->store($u, $org, $v['store_id']);
        $quantity = Quantity::parse($v['quantity'], $m->unit);
        $purchase = null;
        $value = $v['opening_value'] ?? 0;
        if ($v['source'] === 'purchase') {
            $purchase = Purchase::where('organization_id', $org)->where('store_id', $v['store_id'])->lockForUpdate()->findOrFail($v['purchase_id']);
            abort_unless($purchase->version === $v['version'] && $purchase->status === 'approved', 409, 'Pembelian belum disetujui/versi berubah.');
            $lines = $purchase->lines;
            $line = $lines[$m->id] ?? null;
            abort_unless($line && $line['unit'] === $m->unit && $line['quantity'] >= $line['received_quantity'] + $quantity, 409, 'Penerimaan melebihi sisa pembelian.');
            $newReceived = $line['received_quantity'] + $quantity;
            $newValue = Proportion::floor($line['amount'], $newReceived, $line['quantity']);
            $value = $newValue - $line['received_amount'];
            $lines[$m->id]['received_quantity'] = $newReceived;
            $lines[$m->id]['received_amount'] = $newValue;
            $purchase->lines = $lines;
            $purchase->received_amount = Rupiah::add($purchase->received_amount, $value);
            $purchase->version++;
            $purchase->save();
        } else {
            abort_unless(array_key_exists('opening_value', $v) && $v['opening_value'] !== null, 422, 'Nilai saldo awal wajib eksplisit.');
        }
        abort_unless(! $m->expiry_required || isset($v['expires_on']), 422, 'Bahan ini wajib tanggal kedaluwarsa.');
        abort_if(isset($v['expires_on']) && $v['expires_on'] < $v['received_on'], 422, 'Kedaluwarsa sebelum penerimaan.');
        $lot = MaterialLot::create(['organization_id' => $org, 'store_id' => $v['store_id'], 'material_id' => $m->id, 'purchase_id' => $purchase?->id, 'material_name' => $m->name, 'unit' => $m->unit, 'batch_code' => $v['batch_code'], 'received_on' => $v['received_on'], 'expires_on' => $v['expires_on'] ?? null, 'provenance' => ['source' => $v['source'], 'reference' => $v['evidence']], 'evidence' => $v['evidence'], 'remaining_value' => 0, 'usable_quantity' => 0, 'quarantined_quantity' => 0, 'waste_quantity' => 0, 'stock_revision' => 0, 'blocked' => false]);
        $lot = $this->ledger->apply($lot, $u->id, ['quarantined' => $quantity], $value, 'material_receipt', $lot->id, $v['note']);

        return [...$lot->toArray(), 'version' => $lot->stock_revision];
    }

    public function payment(User $u, string $org, array $v): array
    {
        $p = Purchase::where('organization_id', $org)->lockForUpdate()->findOrFail($v['id']);
        abort_unless($p->version === $v['version'] && $p->status === 'approved' && $v['verified'] === true && $v['amount'] <= (($v['kind'] ?? 'payment') === 'supplier_refund' ? max(0, -($p->received_amount - $p->credited_amount - $p->paid_amount + $p->refunded_amount)) : max(0, $p->received_amount - $p->credited_amount - $p->paid_amount + $p->refunded_amount)), 409, 'Versi/saldo atau verifikasi pembayaran tidak sesuai.');
        $payments = $p->payments;
        abort_if(collect($payments)->contains('reference', $v['reference']), 409, 'Referensi pembayaran sudah dipakai.');
        $paymentId = (string) Str::ulid();
        $payments[] = ['id' => $paymentId, 'kind' => $v['kind'] ?? 'payment', 'amount' => $v['amount'], 'method' => $v['method'], 'reference' => $v['reference'], 'paid_on' => $v['paid_on'], 'note' => $v['note']];
        $p->payments = $payments;
        if (($v['kind'] ?? 'payment') === 'supplier_refund') {
            $p->refunded_amount = Rupiah::add($p->refunded_amount, $v['amount']);
        } else {
            $p->paid_amount = Rupiah::add($p->paid_amount, $v['amount']);
        }
        $p->version++;
        $p->save();
        $cash = $v['method'] === 'cash' ? 'cash' : 'bank';
        $terms = ($v['kind'] ?? 'payment') === 'supplier_refund' ? [[$cash, 'payable', $v['amount']]] : [['payable', $cash, $v['amount']]];
        app(AutomaticPosting::class)->record($org, $u->id, 'supplier_payment', $paymentId, $v['paid_on'], $terms, $v['reference']);

        return $p->toArray();
    }
}
