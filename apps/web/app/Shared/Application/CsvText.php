<?php

namespace App\Shared\Application;

final class CsvText
{
    public static function safe(string $value): string
    {
        return (preg_match('/^[\s\p{Z}\p{C}]*[=+\-@＝＋－＠]/u', $value) || preg_match('/^[\t\r\n]/', $value)) ? "'".$value : $value;
    }
}
