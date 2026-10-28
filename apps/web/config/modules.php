<?php

use App\Modules\Catalog\CatalogServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Workspace\WorkspaceServiceProvider;

return ['providers' => [
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    \App\Modules\Operations\OperationsServiceProvider::class,
    \App\Modules\Pos\PosServiceProvider::class,
    WorkspaceServiceProvider::class,
]];
