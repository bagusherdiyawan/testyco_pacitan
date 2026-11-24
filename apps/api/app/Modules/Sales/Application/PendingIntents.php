<?php

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Infrastructure\OfflinePermit;
use App\Modules\Sales\Infrastructure\OfflineSale;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleIntent;
use App\Modules\Sales\Infrastructure\SaleRefund;
use App\Modules\Shifts\Application\ShiftBlockers;

final class PendingIntents implements ShiftBlockers
{
    public function hasPending(string $shift): bool
    {
        return OfflinePermit::where('cash_shift_id', $shift)->whereNull('closed_at')->exists() || OfflineSale::where('cash_shift_id', $shift)->where('status', 'conflict')->exists() || SaleIntent::where('cash_shift_id', $shift)->where('status', 'pending')->exists();
    }

    public function prepareForceClose(string $shift): void
    {
        abort_if(OfflineSale::where('cash_shift_id', $shift)->where('status', 'conflict')->exists(), 409, 'Selesaikan konflik transaksi offline terlebih dahulu sebelum tutup paksa shift.');
        SaleIntent::where('cash_shift_id', $shift)->where('status', 'pending')->update([
            'status' => 'cancelled',
            'cancel_reason' => 'Dibatalkan otomatis saat tutup paksa shift oleh pengelola.',
        ]);
        OfflinePermit::where('cash_shift_id', $shift)->whereNull('closed_at')->update([
            'closed_at' => now(),
        ]);
    }

    public function noncashSummary(string $shift): array
    {
        $sales = Sale::where('cash_shift_id', $shift)
            ->where('payment_method', '!=', 'cash')
            ->get(['payment_method', 'total_amount']);
        $qris = $sales->where('payment_method', 'qris_manual');
        $transfer = $sales->where('payment_method', 'bank_transfer');
        $refunds = (int) SaleRefund::where('cash_shift_id', $shift)
            ->where('payment_method', '!=', 'cash')
            ->sum('amount');

        return [
            'qris_count' => $qris->count(),
            'qris_amount' => (int) $qris->sum('total_amount'),
            'transfer_count' => $transfer->count(),
            'transfer_amount' => (int) $transfer->sum('total_amount'),
            'total_noncash_sales' => (int) $sales->sum('total_amount'),
            'total_noncash_refunds' => $refunds,
        ];
    }
}
