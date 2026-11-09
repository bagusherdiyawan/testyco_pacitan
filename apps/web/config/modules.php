<?php

use App\Modules\Assets\AssetsServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Commercial\CommercialServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Manufacturing\ManufacturingServiceProvider;
use App\Modules\Operations\OperationsServiceProvider;
use App\Modules\Partnership\PartnershipServiceProvider;
use App\Modules\Pos\PosServiceProvider;
use App\Modules\Promotions\PromotionsServiceProvider;
use App\Modules\Reliability\ReliabilityServiceProvider;
use App\Modules\Reporting\ReportingServiceProvider;
use App\Modules\Workspace\WorkspaceServiceProvider;

return ['providers' => [
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    PartnershipServiceProvider::class,
    AssetsServiceProvider::class,
    ManufacturingServiceProvider::class,
    OperationsServiceProvider::class,
    PromotionsServiceProvider::class,
    PosServiceProvider::class,
    BillingServiceProvider::class,
    CommercialServiceProvider::class,
    ReportingServiceProvider::class,
    ReliabilityServiceProvider::class,
    WorkspaceServiceProvider::class,
]];
