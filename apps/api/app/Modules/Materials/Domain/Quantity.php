<?php

namespace App\Modules\Materials\Domain;

/** Exact fixed-point quantities: mg, microlitre, or whole pieces. Never float. */
final class Quantity
{
    public const FACTORS = ['g' => 1000, 'kg' => 1000000, 'ml' => 1000, 'l' => 1000000, 'pcs' => 1];

    public static function parse(mixed $input, string $unit, bool $zero = false): int
    {
        abort_unless(is_string($input) && isset(self::FACTORS[$unit]) && preg_match('/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,3})?$/D', $input), 422, 'Jumlah harus angka desimal maksimal3digit, tanpa pemisah ribuan.');
        [$whole,$fraction] = array_pad(explode('.', $input), 2, '');
        $scaled = (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
        $factor = self::FACTORS[$unit];
        abort_unless(($scaled * $factor) % 1000 === 0, 422, 'Jumlah pcs harus bilangan bulat.');
        $value = intdiv($scaled * $factor, 1000);
        abort_unless($value <= 1000000000000 && ($zero ? $value >= 0 : $value > 0), 422, 'Jumlah di luar batas.');

        return $value;
    }

    public static function display(int $quantity, string $unit): string
    {
        $factor = self::FACTORS[$unit];
        $whole = intdiv($quantity, $factor);
        $remainder = $quantity % $factor;
        if (! $remainder) {
            return (string) $whole;
        }

        return $whole.'.'.rtrim(str_pad((string) $remainder, strlen((string) $factor) - 1, '0'), '0');
    }
}
