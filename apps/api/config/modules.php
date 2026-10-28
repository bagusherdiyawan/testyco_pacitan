<?php

use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Workspace\WorkspaceServiceProvider;

return ['providers' => [
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    \App\Modules\Inventory\InventoryServiceProvider::class,
    \App\Modules\Ordering\OrderingServiceProvider::class,
    \App\Modules\Fulfillment\FulfillmentServiceProvider::class,
    WorkspaceServiceProvider::class,
]];
