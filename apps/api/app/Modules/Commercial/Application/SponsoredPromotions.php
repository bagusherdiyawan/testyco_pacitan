<?php

namespace App\Modules\Commercial\Application;

use App\Modules\Commercial\CommercialServiceProvider;
use App\Modules\Commercial\Infrastructure\CommercialAgreement;
use App\Modules\Promotions\Infrastructure\Voucher;
use App\Modules\Sales\Infrastructure\Sale;
use App\Modules\Sales\Infrastructure\SaleLine;
use App\Modules\Sales\Infrastructure\SaleRefund;
use App\Shared\Domain\Proportion;
use Illuminate\Support\Facades\DB;

final class SponsoredPromotions
{
    public function __construct(private CommercialJournal $journal) {}

    public function quote(Voucher $voucher, int $discount, bool $lock): array
    {
        abort_unless(app()->getProvider(CommercialServiceProvider::class), 409, 'Modul pendanaan sponsor nonaktif.');
        $q = CommercialAgreement::whereKey($voucher->commercial_agreement_id);
        if ($lock) {
            $q->lockForUpdate();
        }$g = $q->firstOrFail();
        $t = $g->terms;
        abort_unless($g->status === 'accepted' && $g->partner_id === $voucher->organization_id && $g->organization_id === $voucher->funding_organization_id, 409, 'Pendanaan sponsor tidak aktif.');
        $amount = Proportion::floor($discount, (int) $t['sponsor_basis_points'], 10000);
        abort_unless($amount + $g->budget_spent <= (int) $t['budget_amount'], 409, 'Anggaran sponsor habis; diskon tidak dialihkan kepada penerbit diam-diam.');

        return ['commercial_agreement_id' => $g->id, 'sponsor_amount' => $amount, 'issuer_amount' => $discount - $amount, 'sponsor_basis_points' => (int) $t['sponsor_basis_points']];
    }

    public function redeemed(array $snapshot, string $sale): void
    {
        if (! isset($snapshot['commercial_agreement_id'])) {
            return;
        }
        $g = CommercialAgreement::lockForUpdate()->findOrFail($snapshot['commercial_agreement_id']);
        $amount = (int) $snapshot['sponsor_amount'];
        abort_unless($g->status === 'accepted' && $g->budget_spent + $amount <= (int) $g->terms['budget_amount'], 409);
        $g->budget_spent += $amount;
        $g->save();
        $s = Sale::findOrFail($sale);
        $this->journal->project($g, 'promo:'.$sale, $s, null, $snapshot['discount_amount'], -$amount, 0, ['sponsor_share' => $amount, 'issuer_share' => $snapshot['issuer_amount'], 'voucher_id' => $snapshot['id'], 'budget_restored' => 0]);
    }

    public function refunded(Sale $s): void
    {
        $snapshot = $s->voucher_snapshot;
        if (! isset($snapshot['commercial_agreement_id'])) {
            return;
        }
        $g = CommercialAgreement::lockForUpdate()->findOrFail($snapshot['commercial_agreement_id']);
        $quantities = [];
        foreach (SaleRefund::where('sale_id', $s->id)->get() as $r) {
            foreach ($r->lines as $l) {
                $quantities[$l['sale_line_id']] = ($quantities[$l['sale_line_id']] ?? 0) + (int) $l['quantity'];
            }
        }
        $discountReversed = 0;
        $qty = 0;
        foreach (SaleLine::where('sale_id', $s->id)->get() as $l) {
            $n = $quantities[$l->id] ?? 0;
            $qty += $n;
            $discountReversed += Proportion::floor($l->discount_amount, $n, $l->quantity);
        }
        $reversed = Proportion::floor($discountReversed, (int) $snapshot['sponsor_basis_points'], 10000);
        $position = DB::table('commercial_positions')->where('commercial_agreement_id', $g->id)->where('source_key', 'promo:'.$s->id)->first();
        abort_unless($position, 409, 'Klaim sponsor asal tidak ditemukan.');
        $oldReversed = (int) $snapshot['sponsor_amount'] + (int) $position->amount;
        if ($g->terms['budget_restore'] === 'proportional_refund') {
            $g->budget_spent -= $reversed - $oldReversed;
            abort_if($g->budget_spent < 0, 409);
            $g->save();
        }
        $this->journal->project($g, 'promo:'.$s->id, $s, null, $snapshot['discount_amount'] - $discountReversed, -((int) $snapshot['sponsor_amount'] - $reversed), $qty, ['sponsor_share' => (int) $snapshot['sponsor_amount'] - $reversed, 'voucher_id' => $snapshot['id'], 'cumulative_discount_reversed' => $discountReversed, 'cumulative_sponsor_reversed' => $reversed]);
    }
}
