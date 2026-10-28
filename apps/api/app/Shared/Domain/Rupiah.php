<?php

namespace App\Shared\Domain;

use DomainException;

final class Rupiah
{
    public const LIMIT = 1000000000000;

    public static function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > self::LIMIT - $right) {
            throw new DomainException('Nominal melebihi batas transaksi development.');
        }

        return $left + $right;
    }

    public static function multiply(int $price, int $quantity): int
    {
        if ($price < 0 || $quantity < 1 || ($price > 0 && $quantity > intdiv(self::LIMIT, $price))) {
            throw new DomainException('Nominal atau jumlah melebihi batas.');
        }

        return $price * $quantity;
    }
}
