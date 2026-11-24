<?php

namespace App\Modules\Shifts\Application;

use App\Models\User;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Shifts\Infrastructure\CashMovement;
use App\Modules\Shifts\Infrastructure\CashMovementRequest;
use App\Modules\Shifts\Infrastructure\CashShift;
use App\Shared\Application\Operations;
use App\Shared\Domain\Rupiah;
use Illuminate\Support\Facades\DB;

final class ShiftBook
{
    public const MOVEMENT_CATEGORIES = [
        'Tambah kembalian',
        'Operasional darurat',
        'Setoran kas',
        'Lainnya',
    ];

    public function __construct(private PosAccess $access, private Operations $operations, private ShiftBlockers $blockers) {}

    public function active(User $user, string $store): ?CashShift
    {
        return CashShift::where('store_id', $store)->where('cashier_id', $user->id)->where('status', 'open')->first();
    }

    public function blockingOpenShift(string $storeId, int $cashierId): ?array
    {
        $store = Store::find($storeId);
        if (! $store || ! $store->single_active_shift) {
            return null;
        }
        $open = CashShift::where('store_id', $storeId)
            ->where('status', 'open')
            ->where('cashier_id', '!=', $cashierId)
            ->orderByDesc('id')
            ->first(['id', 'cashier_id', 'cashier_name', 'device_name', 'opened_at']);

        return $open?->toArray();
    }

    public function latestHandover(string $storeId): ?array
    {
        $prev = CashShift::where('store_id', $storeId)
            ->where('status', 'closed')
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->first(['id', 'cashier_name', 'device_name', 'closed_at', 'counted_cash', 'retained_cash', 'deposit_cash']);
        if (! $prev) {
            return null;
        }
        $expectedOpening = (int) ($prev->retained_cash ?? $prev->counted_cash ?? 0);

        return [
            'previous_shift_id' => $prev->id,
            'previous_cashier_name' => $prev->cashier_name,
            'previous_device_name' => $prev->device_name,
            'closed_at' => $prev->closed_at?->toIso8601String(),
            'expected_opening_cash' => $expectedOpening,
            'previous_deposit_cash' => $prev->deposit_cash,
        ];
    }

    public function presentForViewer(array $shift, bool $canManage): array
    {
        $isBlind = ! $canManage && ($shift['status'] ?? null) === 'open';
        $shift['blind_close'] = $isBlind;
        if ($isBlind) {
            $shift['expected_cash'] = null;
            $shift['cash_sales'] = null;
            $shift['cash_in'] = null;
            $shift['cash_out'] = null;
        }

        return $shift;
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
            $lockedStore = Store::lockForUpdate()->findOrFail($store);
            abort_if(
                CashShift::where('store_id', $store)->where('cashier_id', $user->id)->where('status', 'open')->exists(),
                409,
                'Kasir masih memiliki shift aktif di toko ini.'
            );
            if ($lockedStore->single_active_shift) {
                $otherOpen = CashShift::where('store_id', $store)->where('status', 'open')->first();
                abort_if(
                    $otherOpen !== null,
                    409,
                    'Masih ada shift kasir lain ('.$otherOpen?->cashier_name.') yang belum ditutup di toko ini. Tutup shift sebelumnya secara online sebelum ganti shift.'
                );
            }

            $opening = (int) $v['opening_cash'];
            $openingNote = trim((string) ($v['opening_note'] ?? ''));
            $prev = CashShift::where('store_id', $store)
                ->where('status', 'closed')
                ->orderByDesc('closed_at')
                ->orderByDesc('id')
                ->first();
            $expectedOpening = $prev ? (int) ($prev->retained_cash ?? $prev->counted_cash ?? 0) : null;
            $openingDiscrepancy = $expectedOpening !== null ? $opening - $expectedOpening : null;
            if ($openingDiscrepancy !== null && $openingDiscrepancy !== 0) {
                abort_unless(
                    $openingNote !== '',
                    422,
                    'Kas awal berbeda dari sisa modal shift sebelumnya (Rp '.number_format($expectedOpening, 0, ',', '.').'). Wajib isi catatan selisih serah terima.'
                );
            }

            $shift = CashShift::create([
                'organization_id' => $s->organization_id,
                'store_id' => $store,
                'cashier_id' => $user->id,
                'cashier_name' => $user->name,
                'device_name' => $v['device_name'],
                'status' => 'open',
                'version' => 1,
                'opening_cash' => $opening,
                'previous_shift_id' => $prev?->id,
                'expected_opening_cash' => $expectedOpening,
                'opening_discrepancy' => $openingDiscrepancy,
                'opening_note' => $openingNote !== '' ? $openingNote : null,
                'cash_sales' => 0,
                'cash_in' => 0,
                'cash_out' => 0,
                'expected_cash' => $opening,
                'opened_at' => now(),
            ]);
            $created = DB::table('active_shift_locks')->insertOrIgnore(['store_id' => $store, 'cashier_id' => $user->id, 'cash_shift_id' => $shift->id]);
            abort_unless($created === 1, 409, 'Kasir masih memiliki shift aktif di toko ini.');
            Audit::record($user->id, $s->organization_id, 'shift.opened', $shift->id, null, $shift->toArray());

            return $this->presentForViewer($shift->toArray(), $this->access->canManage($user, $s->organization_id));
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

    public function requestMovement(User $user, string $id, array $v): array
    {
        $initial = CashShift::findOrFail($id);
        $store = $this->access->store($user, $initial->store_id);
        abort_unless((int) $initial->cashier_id === $user->id || $this->access->canManage($user, $store->organization_id), 403, 'Hanya kasir pemilik atau pengelola yang dapat mengajukan mutasi kas.');

        return $this->operations->run($store->organization_id, $user->id, $v['operation_id'], 'shift.cash_req:'.$id, $v, function () use ($user, $id, $v, $store) {
            $shift = CashShift::where('store_id', $store->id)->lockForUpdate()->findOrFail($id);
            $this->assertOpen($shift);
            abort_if(
                CashMovementRequest::where('cash_shift_id', $id)->where('status', 'pending')->count() >= 20,
                409,
                'Terlalu banyak pengajuan kas yang belum diputuskan.'
            );
            $request = CashMovementRequest::create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'cash_shift_id' => $id,
                'requested_by' => $user->id,
                'kind' => $v['kind'],
                'amount' => (int) $v['amount'],
                'category' => trim((string) $v['category']),
                'reason' => trim((string) $v['reason']),
                'evidence_reference' => isset($v['evidence_reference']) && trim((string) $v['evidence_reference']) !== '' ? trim((string) $v['evidence_reference']) : null,
                'status' => 'pending',
                'version' => 1,
                'created_at' => now(),
            ]);
            Audit::record($user->id, $store->organization_id, 'shift.cash_request_created', $request->id, null, $request->toArray());

            return $request->toArray();
        });
    }

    public function approveMovementRequest(User $user, string $requestId, array $v): array
    {
        $initial = CashMovementRequest::findOrFail($requestId);
        $store = $this->access->manager($user, $initial->store_id);
        abort_if((int) $initial->requested_by === $user->id, 403, 'Pengajuan kas tidak boleh disetujui oleh pengaju sendiri.');

        return $this->operations->run($store->organization_id, $user->id, $v['operation_id'], 'shift.cash_req_approve:'.$requestId, $v, function () use ($user, $requestId, $v, $store) {
            $req = CashMovementRequest::lockForUpdate()->findOrFail($requestId);
            abort_unless($req->status === 'pending', 409, 'Pengajuan kas sudah diputuskan.');
            abort_unless($req->version === (int) $v['version'], 409, 'Versi pengajuan kas berubah.');
            $shift = CashShift::lockForUpdate()->findOrFail($req->cash_shift_id);
            $this->assertOpen($shift);

            if ($req->kind === 'out') {
                abort_if($req->amount > $shift->expected_cash, 409, 'Kas keluar melebihi kas yang diharapkan.');
            }
            $before = $shift->toArray();
            $field = $req->kind === 'in' ? 'cash_in' : 'cash_out';
            $expected = $req->kind === 'in' ? Rupiah::add($shift->expected_cash, $req->amount) : $shift->expected_cash - $req->amount;
            $shift->fill([$field => Rupiah::add($shift->{$field}, $req->amount), 'expected_cash' => $expected, 'version' => $shift->version + 1]);
            $shift->save();

            $reason = $req->evidence_reference ? $req->reason.' [Bukti: '.$req->evidence_reference.']' : $req->reason;
            $movement = CashMovement::create([
                'cash_shift_id' => $shift->id,
                'actor_id' => $user->id,
                'kind' => $req->kind,
                'amount' => $req->amount,
                'category' => $req->category,
                'reason' => $reason,
                'created_at' => now(),
            ]);
            $req->fill([
                'status' => 'approved',
                'decided_by' => $user->id,
                'decision_note' => isset($v['decision_note']) && trim((string) $v['decision_note']) !== '' ? trim((string) $v['decision_note']) : 'Disetujui pengelola',
                'cash_movement_id' => $movement->id,
                'version' => $req->version + 1,
                'decided_at' => now(),
            ]);
            $req->save();
            Audit::record($user->id, $store->organization_id, 'shift.cash_request_approved', $req->id, $before, ['shift' => $shift->toArray(), 'movement' => $movement->toArray()]);

            return ['request' => $req->toArray(), 'shift' => $shift->toArray(), 'movement' => $movement->toArray()];
        });
    }

    public function rejectMovementRequest(User $user, string $requestId, array $v): array
    {
        $initial = CashMovementRequest::findOrFail($requestId);
        $store = $this->access->manager($user, $initial->store_id);

        return $this->operations->run($store->organization_id, $user->id, $v['operation_id'], 'shift.cash_req_reject:'.$requestId, $v, function () use ($user, $requestId, $v, $store) {
            $req = CashMovementRequest::lockForUpdate()->findOrFail($requestId);
            abort_unless($req->status === 'pending', 409, 'Pengajuan kas sudah diputuskan.');
            abort_unless($req->version === (int) $v['version'], 409, 'Versi pengajuan kas berubah.');
            $before = $req->toArray();
            $req->fill([
                'status' => 'rejected',
                'decided_by' => $user->id,
                'decision_note' => trim((string) $v['decision_note']),
                'version' => $req->version + 1,
                'decided_at' => now(),
            ]);
            $req->save();
            Audit::record($user->id, $store->organization_id, 'shift.cash_request_rejected', $req->id, $before, $req->toArray());

            return $req->toArray();
        });
    }

    public function cancelMovementRequest(User $user, string $requestId, array $v): array
    {
        $initial = CashMovementRequest::findOrFail($requestId);
        $store = $this->access->store($user, $initial->store_id);
        abort_unless((int) $initial->requested_by === $user->id, 403, 'Hanya pengaju yang dapat membatalkan pengajuan.');

        return $this->operations->run($store->organization_id, $user->id, $v['operation_id'], 'shift.cash_req_cancel:'.$requestId, $v, function () use ($user, $requestId, $v, $store) {
            $req = CashMovementRequest::lockForUpdate()->findOrFail($requestId);
            abort_unless($req->status === 'pending', 409, 'Pengajuan kas sudah diputuskan.');
            abort_unless($req->version === (int) $v['version'], 409, 'Versi pengajuan kas berubah.');
            $before = $req->toArray();
            $req->fill([
                'status' => 'cancelled',
                'decision_note' => trim((string) ($v['reason'] ?? 'Dibatalkan oleh pengaju')),
                'version' => $req->version + 1,
                'decided_at' => now(),
            ]);
            $req->save();
            Audit::record($user->id, $store->organization_id, 'shift.cash_request_cancelled', $req->id, $before, $req->toArray());

            return $req->toArray();
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
            abort_if($this->blockers->hasPending($id), 409, 'Selesaikan atau batalkan draft pembayaran serta izin/konflik offline sebelum tutup shift.');
            abort_if(CashMovementRequest::where('cash_shift_id', $id)->where('status', 'pending')->exists(), 409, 'Selesaikan atau batalkan pengajuan kas masuk/keluar sebelum tutup shift.');

            $counted = (int) $v['counted_cash'];
            $retained = array_key_exists('retained_cash', $v) && $v['retained_cash'] !== null ? (int) $v['retained_cash'] : $counted;
            abort_unless($retained <= $counted, 422, 'Sisa modal di laci tidak boleh melebihi total kas fisik.');
            $deposit = $counted - $retained;

            $difference = $counted - $shift->expected_cash;
            if ($difference !== 0) {
                abort_unless(trim((string) ($v['closing_note'] ?? '')) !== '', 422, 'Selisih kas wajib disertai catatan.');
            }
            $before = $shift->toArray();
            $shift->fill([
                'status' => 'closed',
                'counted_cash' => $counted,
                'retained_cash' => $retained,
                'deposit_cash' => $deposit,
                'difference_cash' => $difference,
                'closing_note' => $v['closing_note'] ?? null,
                'closed_at' => now(),
                'version' => $shift->version + 1,
            ]);
            $shift->save();
            DB::table('active_shift_locks')->where('cash_shift_id', $id)->delete();
            Audit::record($user->id, $store->organization_id, 'shift.closed', $id, $before, $shift->toArray());

            return [...$shift->toArray(), 'blind_close' => false];
        });
    }

    public function forceClose(User $user, string $id, array $v): array
    {
        $initial = CashShift::findOrFail($id);
        $store = $this->access->manager($user, $initial->store_id, false);

        return $this->operations->run($store->organization_id, $user->id, $v['operation_id'], 'shift.force_close:'.$id, $v, function () use ($user, $id, $v, $store) {
            $shift = CashShift::where('store_id', $store->id)->lockForUpdate()->findOrFail($id);
            $this->assertOpen($shift);
            abort_unless($shift->version === (int) $v['version'], 409, 'Kas berubah setelah penghitungan. Muat ulang.');
            $this->blockers->prepareForceClose($id);
            CashMovementRequest::where('cash_shift_id', $id)->where('status', 'pending')->update([
                'status' => 'cancelled',
                'decided_by' => $user->id,
                'decision_note' => 'Dibatalkan otomatis saat tutup paksa shift.',
                'decided_at' => now(),
            ]);

            $counted = (int) $v['counted_cash'];
            $retained = array_key_exists('retained_cash', $v) && $v['retained_cash'] !== null ? (int) $v['retained_cash'] : $counted;
            abort_unless($retained <= $counted, 422, 'Sisa modal di laci tidak boleh melebihi total kas fisik.');
            $deposit = $counted - $retained;
            $difference = $counted - $shift->expected_cash;
            $reason = trim((string) $v['reason']);

            $before = $shift->toArray();
            $shift->fill([
                'status' => 'closed',
                'counted_cash' => $counted,
                'retained_cash' => $retained,
                'deposit_cash' => $deposit,
                'difference_cash' => $difference,
                'closing_note' => trim((string) ($v['closing_note'] ?? '')) ?: $reason,
                'force_closed_by' => $user->id,
                'force_close_reason' => $reason,
                'closed_at' => now(),
                'version' => $shift->version + 1,
            ]);
            $shift->save();
            DB::table('active_shift_locks')->where('cash_shift_id', $id)->delete();
            Audit::record($user->id, $store->organization_id, 'shift.force_closed', $id, $before, $shift->toArray());

            return [...$shift->toArray(), 'blind_close' => false];
        });
    }

    public function updateStorePolicy(User $user, string $storeId, array $v): array
    {
        $store = $this->access->manager($user, $storeId, false);

        return $this->operations->run($store->organization_id, $user->id, $v['operation_id'], 'store.pos_policy:'.$storeId, $v, function () use ($user, $storeId, $v) {
            $locked = Store::lockForUpdate()->findOrFail($storeId);
            $before = $locked->toArray();
            $locked->single_active_shift = (bool) $v['single_active_shift'];
            $locked->save();
            Audit::record($user->id, $locked->organization_id, 'store.pos_policy_updated', $locked->id, $before, $locked->toArray());

            return $locked->toArray();
        });
    }
}
