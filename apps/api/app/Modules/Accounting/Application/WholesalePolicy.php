<?php

namespace App\Modules\Accounting\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WholesalePolicy
{
    public const ROLES = [
        'trade_receivable' => ['Piutang wholesale / kredit pelanggan', 'asset', 'debit'],
        'wholesale_sales' => ['Pendapatan wholesale bruto', 'revenue', 'credit'],
        'wholesale_discount' => ['Diskon wholesale', 'expense', 'debit'],
        'wholesale_returns' => ['Nota kredit wholesale', 'expense', 'debit'],
        'shipping_revenue' => ['Pendapatan ongkir wholesale', 'revenue', 'credit'],
        'output_tax' => ['Pajak keluaran invoice', 'liability', 'credit'],
        'trade_payable' => ['Utang dagang wholesale / refund supplier', 'liability', 'credit'],
        'purchase_clearing' => ['Penampung pembelian sebelum rekonsiliasi persediaan', 'asset', 'debit'],
        'input_tax' => ['Pajak masukan dapat dikreditkan', 'asset', 'debit'],
        'freight_expense' => ['Beban ongkir pembelian', 'expense', 'debit'],
    ];

    public function baseGuard(string $org): void
    {
        abort_if(DB::table('accounting_policies as p')->join('accounting_wholesale_policies as w', 'w.policy_id', '=', 'p.id')->where('p.organization_id', $org)->where('w.organization_id', $org)->where('p.status', 'active')->exists(), 409, 'Hentikan konfigurasi wholesale aktif sebelum mengganti pemetaan dasar. Koreksi dokumen lama tetap memakai pemetaan asal.');
    }

    public function propose(User $u, string $org, array $v): array
    {
        $base = DB::table('accounting_policies')->where('organization_id', $org)->where('id', $v['base_policy_id'])->where('status', 'active')->lockForUpdate()->firstOrFail();
        abort_unless($base->version === $v['base_policy_version'], 409, 'Pemetaan dasar berubah.');
        $mapping = array_intersect_key(json_decode($base->mapping, true), AutomaticPosting::ROLES);
        foreach (self::ROLES as $role => [$label,$kind,$normal]) {
            $a = DB::table('accounting_accounts')->where('organization_id', $org)->where('id', $v[$role.'_account'])->where('active', true)->firstOrFail();
            abort_unless($a->kind === $kind && $a->normal_balance === $normal, 422, 'Kelompok/saldo akun tidak sesuai untuk '.$label);
            $mapping[$role] = ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'kind' => $a->kind, 'normal_balance' => $a->normal_balance];
        }
        abort_unless(count(array_unique(array_column($mapping, 'account_id'))) === count($mapping), 422, 'Gunakan akun tersendiri per fungsi agar tidak mencampur POS/wholesale.');
        $p = ['id' => (string) Str::ulid(), 'organization_id' => $org, 'status' => 'draft', 'version' => 1, 'mapping' => json_encode($mapping), 'note' => $v['note'], 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()];
        DB::table('accounting_policies')->insert($p);
        DB::table('accounting_wholesale_policies')->insert(['policy_id' => $p['id'], 'organization_id' => $org, 'base_policy_id' => $base->id, 'base_policy_version' => $base->version, 'tax_treatment' => $v['tax_treatment'], 'freight_treatment' => $v['freight_treatment'], 'created_at' => now()]);

        return $p;
    }

    public function approvalGuard(string $org, string $id): void
    {
        $w = DB::table('accounting_wholesale_policies')->where('organization_id', $org)->where('policy_id', $id)->first();
        if (! $w) {
            $this->baseGuard($org);

            return;
        }
        abort_unless(DB::table('accounting_policies')->where('organization_id', $org)->where('id', $w->base_policy_id)->where('version', $w->base_policy_version)->where('status', 'active')->exists(), 409, 'Pemetaan dasar berubah sejak pengajuan; ajukan dan tinjau ulang.');
    }

    public static function labels(): array
    {
        return [...AutomaticPosting::ROLES, ...self::ROLES];
    }
}
