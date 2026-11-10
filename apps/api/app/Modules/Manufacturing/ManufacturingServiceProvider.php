<?php

namespace App\Modules\Manufacturing;

use App\Modules\Identity\Presentation\ActiveUser;
use App\Modules\Inventory\InventoryServiceProvider;
use App\Modules\Manufacturing\Presentation\ErpController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ManufacturingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! app()->getProvider(InventoryServiceProvider::class)) {
            throw new \LogicException('Manufacturing requires Inventory.');
        }
        Route::prefix('api/v1/organizations/{organization}/erp')->middleware(['api', 'auth:sanctum', ActiveUser::class, 'throttle:business'])->group(function () {
            Route::get('context', [ErpController::class, 'context']);
            Route::post('{action}', [ErpController::class, 'mutate'])->where('action', 'commercial_policy|closing_propose|closing_decide|invoice_revalue_retry|wholesale_policy|posting_policy|posting_decide|posting_suspend|posting_reconcile|posting_retry|value_propose|value_decide|account|accounting_period|journal|journal_decide|journal_reverse|period_status|operator_grant|material|material_configure|supplier|purchase|purchase_decide|receive|payment_reverse|payment|recipe|production|production_cancel|complete|material_quality|production_quality|transfer|count|count_decide');
        });
    }
}
