<?php

use App\Modules\Assets\AssetsServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Commercial\CommercialServiceProvider;
use App\Modules\Fulfillment\FulfillmentServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Ordering\OrderingServiceProvider;
use App\Modules\Partnership\PartnershipServiceProvider;
use App\Modules\Promotions\PromotionsServiceProvider;
use App\Modules\Reliability\ReliabilityServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Sales\SalesServiceProvider;
use App\Modules\Shifts\ShiftsServiceProvider;
use App\Modules\Workspace\WorkspaceServiceProvider;

return ['providers' => [
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    PartnershipServiceProvider::class,
    AssetsServiceProvider::class,
    InventoryServiceProvider::class,
    OrderingServiceProvider::class,
    FulfillmentServiceProvider::class,
    ShiftsServiceProvider::class,
    PromotionsServiceProvider::class,
    SalesServiceProvider::class,
    BillingServiceProvider::class,
    CommercialServiceProvider::class,
    ReportingServiceProvider::class,
    ReliabilityServiceProvider::class,
    WorkspaceServiceProvider::class,
]];
