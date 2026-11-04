<?php

namespace App\Modules\Shifts\Application;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Shifts\Infrastructure\CashMovement;
use App\Modules\Shifts\Infrastructure\CashShift;
use App\Shared\Application\Operations;
use App\Shared\Domain\Rupiah;
use Illuminate\Support\Facades\DB;

final class ShiftBook
{
    public function __construct(private PosAccess $access, private Operations $operations, private ShiftBlockers $blockers) {}

    public function active(User $user, string $store): ?CashShift
    {
        return CashShift::where('store_id', $store)->where('cashier_id', $user->id)->where('status', 'open')->first();
    }

    public function lockOwned(User $user, string $store, string $shift): CashShift
    {
        return CashShift::where('store_id', $store)->where('cashier_id', $user->id)->lockForUpdate()->findOrFail($shift);
    }

    public function assertOpen(CashShift $shift): void
    {
        abort_unless($shift->status === 'open', 409, 'Shift sudah ditutup.');
    }

    public function recordSale(CashShift $shift, int $amount, string $method = 'cash'): void
    {
        $this->assertOpen($shift);
        $shift->fill($method === 'cash' ? ['expected_cash' => Rupiah::add($shift->expected_cash, $amount), 'cash_sales' => Rupiah::add($shift->cash_sales, $amount), 'version' => $shift->version + 1] : ['noncash_sales' => Rupiah::add($shift->noncash_sales, $amount), 'version' => $shift->version + 1]);
        $shift->save();
    }

    public function refundShift(User $user, string $store, string $id, int $version): CashShift
    {
        $this->access->manager($user, $store);
        $shift = CashShift::where('store_id', $store)->lockForUpdate()->findOrFail($id);
        $this->assertOpen($shift);
        abort_unless($shift->version === $version, 409, 'Versi shift refund berubah.');

        return $shift;
    }

    public function recordRefund(CashShift $shift, User $user, int $amount, string $method, string $reference, string $reason): void
    {
        $this->assertOpen($shift);
        if ($method === 'cash') {
            abort_unless($amount <= $shift->expected_cash, 409, 'Kas shift tidak cukup untuk refund.');
            $shift->fill(['expected_cash' => $shift->expected_cash - $amount, 'cash_out' => Rupiah::add($shift->cash_out, $amount)]);
            if ($amount > 0) {
                CashMovement::create(['cash_shift_id' => $shift->id, 'actor_id' => $user->id, 'kind' => 'out', 'amount' => $amount, 'category' => 'refund', 'reason' => $reference.' · '.$reason, 'created_at' => now()]);
            }
        } else {
            $shift->noncash_refunds = Rupiah::add($shift->noncash_refunds, $amount);
        }$shift->version++;
        $shift->save();
    }

    public function open(User $user, string $store, array $v): array
    {
        $s = $this->access->store($user, $store);

        return $this->operations->run($s->organization_id, $user->id, $v['operation_id'], 'shift.open:'.$store, $v, function () use ($user, $store, $s, $v) {
            $shift = CashShift::create(['organization_id' => $s->organization_id, 'store_id' => $store, 'cashier_id' => $user->id, 'cashier_name' => $user->name, 'device_name' => $v['device_name'], 'status' => 'open', 'version' => 1, 'opening_cash' => (int) $v['opening_cash'], 'cash_sales' => 0, 'cash_in' => 0, 'cash_out' => 0, 'expected_cash' => (int) $v['opening_cash'], 'opened_at' => now()]);
            $created = DB::table('active_shift_locks')->insertOrIgnore(['store_id' => $store, 'cashier_id' => $user->id, 'cash_shift_id' => $shift->id]);
            abort_unless($created === 1, 409, 'Kasir masih memiliki shift aktif di toko ini.');
            Audit::record($user->id, $s->organization_id, 'shift.opened', $shift->id, null, $shift->toArray());

            return $shift->toArray();
        });
    }

    public function movement(User $user, string $id, array $v): array
    {
        $initial = CashShift::findOrFail($id);
        $store = $this->access->manager($user, $initial->store_id);

        return $this->operations->run($store->organization_id, $user->id, $v['operation_id'], 'shift.cash:'.$id, $v, function () use ($user, $id, $v, $store) {
            $shift = CashShift::lockForUpdate()->findOrFail($id);
            $this->assertOpen($shift);
            abort_unless($shift->version === (int) $v['version'], 409, 'Versi kas berubah. Muat ulang.');
            $amount = (int) $v['amount'];
            if ($v['kind'] === 'out') {
                abort_if($amount > $shift->expected_cash, 409, 'Kas keluar melebihi kas yang diharapkan.');
            }
            $before = $shift->toArray();
            $field = $v['kind'] === 'in' ? 'cash_in' : 'cash_out';
            $expected = $v['kind'] === 'in' ? Rupiah::add($shift->expected_cash, $amount) : $shift->expected_cash - $amount;
            $shift->fill([$field => Rupiah::add($shift->{$field}, $amount), 'expected_cash' => $expected, 'version' => $shift->version + 1]);
            $shift->save();
            $movement = CashMovement::create(['cash_shift_id' => $id, 'actor_id' => $user->id, 'kind' => $v['kind'], 'amount' => $amount, 'category' => $v['category'], 'reason' => $v['reason'], 'created_at' => now()]);
            Audit::record($user->id, $store->organization_id, 'shift.cash_movement', $movement->id, $before, $shift->toArray());

            return ['shift' => $shift->toArray(), 'movement' => $movement->toArray()];
        });
    }

    public function close(User $user, string $id, array $v): array
    {
        $initial = CashShift::findOrFail($id);
        $store = $this->access->store($user, $initial->store_id);
        abort_unless((int) $initial->cashier_id === $user->id, 403, 'Shift hanya ditutup oleh kasir pemilik.');

        return $this->operations->run($store->organization_id, $user->id, $v['operation_id'], 'shift.close:'.$id, $v, function () use ($user, $id, $v, $store) {
            $shift = $this->lockOwned($user, $store->id, $id);
            $this->assertOpen($shift);
            abort_unless($shift->version === (int) $v['version'], 409, 'Kas berubah setelah penghitungan. Muat ulang dan hitung kembali.');
            abort_if($this->blockers->hasPending($id), 409, 'Selesaikan atau batalkan draft pembayaran sebelum tutup shift.');
            $difference = (int) $v['counted_cash'] - $shift->expected_cash;
            if ($difference !== 0) {
                abort_unless(trim((string) ($v['closing_note'] ?? '')) !== '', 422, 'Selisih kas wajib disertai catatan.');
            }
            $before = $shift->toArray();
            $shift->fill(['status' => 'closed', 'counted_cash' => (int) $v['counted_cash'], 'difference_cash' => $difference, 'closing_note' => $v['closing_note'] ?? null, 'closed_at' => now(), 'version' => $shift->version + 1]);
            $shift->save();
            DB::table('active_shift_locks')->where('cash_shift_id', $id)->delete();
            Audit::record($user->id, $store->organization_id, 'shift.closed', $id, $before, $shift->toArray());

            return $shift->toArray();
        });
    }
}
