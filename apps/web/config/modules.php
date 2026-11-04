<?php

use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Operations\OperationsServiceProvider;
use App\Modules\Partnership\PartnershipServiceProvider;
use App\Modules\Pos\PosServiceProvider;
use App\Modules\Promotions\PromotionsServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Workspace\WorkspaceServiceProvider;

return ['providers' => [
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    PartnershipServiceProvider::class,
    OperationsServiceProvider::class,
    PromotionsServiceProvider::class,
    PosServiceProvider::class,
    BillingServiceProvider::class,
    ReportingServiceProvider::class,
    WorkspaceServiceProvider::class,
]];
