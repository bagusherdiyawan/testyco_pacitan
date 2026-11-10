<?php

namespace App\Modules\Manufacturing\Application;

use App\Models\User;
use App\Modules\Accounting\Application\AutomaticPosting;
use App\Modules\Accounting\Application\CommercialPolicy;
use App\Modules\Accounting\Application\DistributionCosting;
use App\Modules\Accounting\Application\GeneralLedger;
use App\Modules\Accounting\Application\InventoryValuation;
use App\Modules\Accounting\Application\InvoiceValuation;
use App\Modules\Accounting\Application\MissingCostSettlement;
use App\Modules\Accounting\Application\PeriodClosing;
use App\Modules\Accounting\Application\WholesalePolicy;
use App\Modules\Identity\Application\Audit;
use App\Modules\Identity\Infrastructure\Organization;
use App\Modules\Materials\Application\MaterialConfiguration;
use App\Modules\Materials\Application\MaterialControl;
use App\Modules\Materials\Domain\Quantity;
use App\Modules\Materials\Infrastructure\Material;
use App\Modules\Procurement\Application\Purchasing;
use App\Modules\Procurement\Infrastructure\Supplier;
use App\Modules\Quality\Application\Inspections;
use App\Shared\Application\Operations;

final class ErpWorkflow
{
    public function __construct(private ErpAccess $access, private Operations $ops, private Purchasing $purchases, private ProductionWorkflow $production, private Inspections $quality, private MaterialControl $control) {}

    public function run(User $u, string $org, string $action, array $v): array
    {
        $this->access->command($u, $org, $action, $v);

        return $this->ops->run($org, $u->id, $v['operation_id'], 'erp.'.$action.':'.$org, $v, function () use ($u, $org, $action, $v) {
            if (in_array($action, ['missing_propose', 'missing_decide'], true)) {
                return [...app(MissingCostSettlement::class)->command($u, $org, $action, $v), 'operation_id' => $v['operation_id']];
            }
            Organization::whereKey($org)->lockForUpdate()->firstOrFail();
            $this->access->command($u, $org, $action, $v);
            if ($action === 'material') {
                abort_if(Material::where('organization_id', $org)->where('code', $v['code'])->exists(), 409, 'Kode bahan sudah digunakan.');
            }
            $result = match ($action) {
                'material' => Material::create(['organization_id' => $org, 'code' => $v['code'], 'name' => $v['name'], 'unit' => $v['unit'], 'minimum_quantity' => Quantity::parse($v['minimum'], $v['unit'], true), 'expiry_required' => $v['expiry_required'], 'fefo_required' => $v['fefo_required'], 'active' => true, 'version' => 1])->toArray(),
                'material_configure' => app(MaterialConfiguration::class)->update($org, $v),
                'operator_grant' => app(OperatorPermissions::class)->set($u, $org, $v),
                'commercial_policy' => app(CommercialPolicy::class)->propose($u, $org, $v),
                'closing_propose' => app(PeriodClosing::class)->propose($u, $org, $v),
                'closing_decide' => app(PeriodClosing::class)->decide($u, $org, $v),
                'distribution_retry' => app(DistributionCosting::class)->retry($org, $v),
                'invoice_revalue_retry' => app(InvoiceValuation::class)->retry($org, $v),
                'wholesale_policy' => app(WholesalePolicy::class)->propose($u, $org, $v),
                'posting_policy' => app(AutomaticPosting::class)->policy($u, $org, $v),
                'posting_decide' => app(AutomaticPosting::class)->decide($u, $org, $v),
                'posting_suspend' => app(AutomaticPosting::class)->suspend($org, $v),
                'posting_reconcile' => app(AutomaticPosting::class)->reconcile($u, $org, $v),
                'posting_retry' => app(AutomaticPosting::class)->retry($org, $v),
                'value_propose' => app(InventoryValuation::class)->propose($u, $org, $v),
                'value_decide' => app(InventoryValuation::class)->decide($u, $org, $v),
                'account' => app(GeneralLedger::class)->account($org, $v),
                'accounting_period' => app(GeneralLedger::class)->period($org, $v),
                'journal' => app(GeneralLedger::class)->journal($u, $org, $v),
                'journal_decide' => app(GeneralLedger::class)->decide($u, $org, $v),
                'journal_reverse' => app(GeneralLedger::class)->reverse($u, $org, $v),
                'period_status' => app(GeneralLedger::class)->periodStatus($org, $v, $u->id),
                'supplier' => Supplier::create(['organization_id' => $org, 'name' => $v['name'], 'contact' => $v['contact'], 'address' => $v['address'], 'version' => 1])->toArray(),
                'purchase' => $this->purchases->create($u, $org, $v),
                'purchase_decide' => $this->purchases->decide($org, $v),
                'receive' => $this->purchases->receive($u, $org, $v),
                'payment_reverse' => $this->purchases->reversePayment($u, $org, $v),
                'payment' => $this->purchases->payment($u, $org, $v),
                'recipe' => $this->production->recipe($org, $v),
                'production' => $this->production->create($u, $org, $v),
                'production_cancel' => $this->production->cancel($org, $v),
                'complete' => $this->production->complete($u, $org, $v),
                'material_quality' => $this->quality->material($u, $org, $v),
                'production_quality' => $this->quality->production($u, $org, $v),
                'transfer' => $this->control->transfer($u, $org, $v),
                'count' => $this->control->propose($u, $org, $v),
                'count_decide' => $this->control->decide($u, $org, $v),
            };
            Audit::record($u->id, $org, 'erp.'.$action, $result['id'], null, ['operation_id' => $v['operation_id'], 'request' => $v, 'result' => $result]);

            return [...$result, 'operation_id' => $v['operation_id']];
        });
    }
}
