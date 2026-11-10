<?php

namespace App\Modules\Commercial\Application;

use App\Modules\Accounting\Application\CommercialPosting;
use App\Modules\Commercial\Infrastructure\CommercialAgreement;
use App\Modules\Sales\Infrastructure\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommercialJournal
{
    /** Caller holds agreement lock; projection updates and immutable delta commit together. */
    public function project(CommercialAgreement $g, string $source, Sale $sale, ?string $line, int $basis, int $amount, int $refundQty, array $snapshot): void
    {
        $p = DB::table('commercial_positions')->where('commercial_agreement_id', $g->id)->where('source_key', $source)->lockForUpdate()->first();
        if ($p && (int) $p->basis_amount === $basis && (int) $p->amount === $amount && (int) $p->refunded_quantity === $refundQty) {
            DB::table('commercial_positions')->where('id', $p->id)->update(['sale_version' => $sale->version]);

            return;
        }
        $id = $p?->id ?? (string) Str::ulid();
        $version = (int) ($p?->version ?? 0) + 1;
        $d = ['commercial_agreement_id' => $g->id, 'source_key' => $source, 'sale_id' => $sale->id, 'sale_line_id' => $line, 'sale_version' => $sale->version, 'version' => $version, 'basis_amount' => $basis, 'amount' => $amount, 'refunded_quantity' => $refundQty, 'updated_at' => now()];
        if ($p) {
            DB::table('commercial_positions')->where('id', $id)->update($d);
        } else {
            DB::table('commercial_positions')->insert(['id' => $id, ...$d]);
        }
        $entryId = (string) Str::ulid();
        $priorEntry = DB::table('commercial_entries')->where('commercial_position_id', $id)->orderByDesc('position_version')->first();
        $priorTax = $priorEntry ? (int) (json_decode($priorEntry->snapshot, true)['tax_amount'] ?? 0) : 0;
        DB::table('commercial_entries')->insert(['id' => $entryId, 'commercial_agreement_id' => $g->id, 'commercial_position_id' => $id, 'position_version' => $version, 'amount' => $amount - (int) ($p?->amount ?? 0), 'basis_amount' => $basis - (int) ($p?->basis_amount ?? 0), 'snapshot' => json_encode(['source_key' => $source, 'original_receipt' => $sale->number, 'original_sold_at' => $sale->sold_at->toIso8601String(), 'sale_version' => $sale->version, 'agreement_id' => $g->id, 'terms' => $g->terms, ...$snapshot], JSON_THROW_ON_ERROR), 'created_at' => now()]);
        app(CommercialPosting::class)->entry($g, $entryId, $id, $version, $sale, $amount - (int) ($p?->amount ?? 0), (int) ($snapshot['tax_amount'] ?? 0) - $priorTax, $priorEntry ? (int) json_decode($priorEntry->snapshot, true)['sale_version'] : null, $refundQty);
    }
}
