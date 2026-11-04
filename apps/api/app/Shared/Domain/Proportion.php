<?php

namespace App\Shared\Domain;

/** Exact floor(a*b/d) for bounded non-negative IDR; avoids floating point and multiplication overflow. */
final class Proportion
{
    public static function floor(int $a, int $b, int $d): int
    {
        if ($a < 0 || $b < 0 || $d < 1 || $a > Rupiah::LIMIT || $b > Rupiah::LIMIT) {
            throw new \DomainException('Proporsi nominal tidak valid.');
        }$bits = [];
        while ($b > 0) {
            $bits[] = $b % 2;
            $b = intdiv($b, 2);
        }$q = 0;
        $r = 0;
        foreach (array_reverse($bits) as $bit) {
            $q *= 2;
            $r *= 2;
            if ($bit) {
                $q += intdiv($a, $d);
                $r += $a % $d;
            }$q += intdiv($r, $d);
            $r %= $d;
            if ($q > Rupiah::LIMIT) {
                throw new \DomainException('Proporsi melebihi batas.');
            }
        }

        return $q;
    }
}
