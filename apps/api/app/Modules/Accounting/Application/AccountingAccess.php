<?php

namespace App\Modules\Accounting\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Infrastructure\Organization;

final class AccountingAccess
{
    public const ACTIONS = ['account', 'accounting_period', 'journal', 'journal_decide', 'journal_reverse', 'period_status', 'invoice_revalue_retry', 'wholesale_policy', 'posting_policy', 'posting_decide', 'posting_suspend', 'value_propose', 'value_decide', 'posting_reconcile', 'posting_retry'];

    public function manage(User $user, string $org): void
    {
        $o = Organization::whereKey($org)->where('active', true)->firstOrFail();
        $role = app(Access::class)->role($user, $org)?->value;
        abort_unless(($o->type === 'center' && in_array($role, ['owner', 'admin'], true)) || ($o->type === 'partner' && $role === 'partner_lead'), 403, 'Pembukuan memerlukan pengelola organisasi sendiri.');
    }
}
