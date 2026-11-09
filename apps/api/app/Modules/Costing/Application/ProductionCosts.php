<?php

namespace App\Modules\Costing\Application;

use App\Shared\Domain\Rupiah;

final class ProductionCosts
{
    public function calculate(int $goods, int $labor, int $overhead, string $evidence, ?int $pieces, ?int $quantity, string $unit): array
    {
        $total = Rupiah::add($goods, Rupiah::add($labor, $overhead));

        return ['goods_cost' => $goods, 'labor_cost' => $labor, 'overhead_cost' => $overhead, 'total_amount' => $total, 'allocation' => 'all_to_good_output', 'evidence' => $evidence, 'output_pieces' => $pieces, 'output_quantity' => $quantity, 'output_unit' => $unit, 'unit_cost_floor_idr' => $pieces > 0 ? intdiv($total, $pieces) : null, 'rounding_remainder_idr' => $pieces > 0 ? $total % $pieces : null];
    }
}
