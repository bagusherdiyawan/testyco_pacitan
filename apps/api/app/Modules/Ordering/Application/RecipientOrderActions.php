<?php

namespace App\Modules\Ordering\Application;

use App\Models\User;
use App\Modules\Identity\Application\Access;
use App\Modules\Identity\Infrastructure\Store;
use App\Modules\Ordering\Domain\OrderState;
use App\Modules\Ordering\Infrastructure\SupplyOrder;

final class RecipientOrderActions
{
    public function __construct(private Access $access) {}

    public function for(User $user, SupplyOrder $order, string $side, bool $fulfillment): array
    {
        $role = $this->access->role($user, $order->organization_id)?->value;
        $operating = $side === 'recipient' && in_array($role, OrderAccess::RECIPIENT_ROLES, true) && Store::whereKey($order->store_id)->where('active', true)->exists();
        $decide = $operating && in_array($role, $order->kind === 'internal_transfer' ? ['owner', 'admin'] : ['partner_lead'], true);
        $state = OrderState::from($order->status);

        return ['approve' => $decide && $state->allows('approve'), 'reject' => $decide && $state->allows('reject'), 'cancel' => $decide && $state->allows('cancel'), 'receive' => $operating && $fulfillment && $state->allows('receive')];
    }
}
