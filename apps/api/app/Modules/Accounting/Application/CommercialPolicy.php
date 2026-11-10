<?php

namespace App\Modules\Accounting\Application;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CommercialPolicy
{
    public const ROLES = [
        'share_receivable' => ['Piutang hak bagi hasil / saldo kredit', 'asset', 'debit'],
        'share_payable' => ['Utang bagi hasil / saldo debit', 'liability', 'credit'],
        'share_income' => ['Pendapatan hak bagi hasil kontraktual', 'revenue', 'credit'],
        'share_expense' => ['Beban bagi hasil kontraktual', 'expense', 'debit'],
        'share_output_tax' => ['Pajak keluaran bagi hasil', 'liability', 'credit'],
        'share_input_tax' => ['Pajak masukan bagi hasil', 'asset', 'debit'],
        'sponsor_receivable' => ['Piutang pendanaan sponsor', 'asset', 'debit'],
        'sponsor_payable' => ['Utang pendanaan promo sponsor', 'liability', 'credit'],
        'sponsor_expense' => ['Beban pendanaan sponsor', 'expense', 'debit'],
        'sponsor_recovery' => ['Pemulihan diskon dari sponsor', 'revenue', 'credit'],
    ];

    public function propose(User $u, string $org, array $v): array
    {
        $base = DB::table('accounting_policies')->where('organization_id', $org)->where('id', $v['base_policy_id'])->where('status', 'active')->lockForUpdate()->firstOrFail();
        abort_unless($base->version === $v['base_policy_version'], 409, 'Pemetaan dasar berubah.');
        $mapping = json_decode($base->mapping, true);
        foreach (self::ROLES as $role => [$label,$kind,$normal]) {
            $a = DB::table('accounting_accounts')->where('organization_id', $org)->where('id', $v[$role.'_account'])->where('active', true)->firstOrFail();
            abort_unless($a->kind === $kind && $a->normal_balance === $normal, 422, 'Akun tidak sesuai untuk '.$label);
            $mapping[$role] = ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'kind' => $a->kind, 'normal_balance' => $a->normal_balance];
        }
        abort_unless(count(array_unique(array_column($mapping, 'account_id'))) === count($mapping), 422, 'Setiap fungsi memerlukan akun tersendiri.');
        $p = ['id' => (string) Str::ulid(), 'organization_id' => $org, 'status' => 'draft', 'version' => 1, 'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR), 'note' => $v['note'], 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()];
        DB::table('accounting_policies')->insert($p);
        DB::table('accounting_commercial_policies')->insert(['policy_id' => $p['id'], 'organization_id' => $org, 'base_policy_id' => $base->id, 'base_policy_version' => $base->version, 'recognition_basis' => $v['recognition_basis'], 'tax_treatment' => $v['tax_treatment'], 'created_at' => now()]);
        if ($w = DB::table('accounting_wholesale_policies')->where('organization_id', $org)->where('policy_id', $base->id)->first()) {
            DB::table('accounting_wholesale_policies')->insert([...(array) $w, 'policy_id' => $p['id'], 'base_policy_id' => $base->id, 'base_policy_version' => $base->version, 'created_at' => now()]);
        }

        return $p;
    }

    public function guard(string $org): void
    {
        abort_if(DB::table('accounting_commercial_policies as c')->join('accounting_policies as p', 'p.id', '=', 'c.policy_id')->where('c.organization_id', $org)->where('p.status', 'active')->exists(), 409, 'Pertahankan integrasi komersial melalui proposal komersial baru, atau hentikan konfigurasi aktif secara eksplisit sebelum mengganti cakupan.');
    }

    public function approval(string $org, string $id): void
    {
        $c = DB::table('accounting_commercial_policies')->where('organization_id', $org)->where('policy_id', $id)->first();
        if (! $c) {
            $this->guard($org);

            return;
        }
        abort_unless(DB::table('accounting_policies')->where('organization_id', $org)->where('id', $c->base_policy_id)->where('version', $c->base_policy_version)->where('status', 'active')->exists(), 409, 'Pemetaan komersial dasar berubah sejak pengajuan.');
    }
}
