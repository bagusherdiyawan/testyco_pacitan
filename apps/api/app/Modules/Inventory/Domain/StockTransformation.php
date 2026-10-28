<?php

namespace App\Modules\Inventory\Domain;

use DomainException;

final readonly class StockTransformation
{
    private function __construct(public int $input, public int $output, public int $waste, public array $changes) {}

    public static function open(int $packSize, int $packs): self
    {
        if ($packSize < 1 || $packs < 1) {
            throw new DomainException('Jumlah kemasan dan isi kemasan harus positif.');
        }
        $pieces = $packSize * $packs;

        return new self($pieces, $pieces, 0, ['usable' => -$pieces, 'opened' => $pieces]);
    }

    public static function cook(int $input, int $actualOutput): self
    {
        if ($input < 1 || $actualOutput < 0 || $actualOutput > $input) {
            throw new DomainException('Hasil aktual tidak boleh negatif atau melebihi jumlah yang dikukus.');
        }
        $waste = $input - $actualOutput;

        return new self($input, $actualOutput, $waste, ['opened' => -$input, 'ready' => $actualOutput, 'waste' => $waste]);
    }
}
