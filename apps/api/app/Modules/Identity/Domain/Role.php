<?php

namespace App\Modules\Identity\Domain;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Warehouse = 'warehouse';
    case PartnerLead = 'partner_lead';
    case StoreManager = 'store_manager';
    case Cashier = 'cashier';

    public function organizationWide(): bool
    {
        return in_array($this, [self::Owner, self::Admin, self::PartnerLead], true);
    }

    public function manages(): bool
    {
        return $this->organizationWide();
    }
}
