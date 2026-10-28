<?php

namespace App\Modules\Reporting\Domain;

final class CsvText
{
    public static function safe(string $text): string
    {
        // Spreadsheet formula prefixes, including whitespace/control and full-width forms.
        return preg_match('/^[\s\p{Z}\p{C}]*[=+\-@＝＋－＠]/u', $text) || preg_match('/^[\t\r\n]/', $text) ? "'".$text : $text;
    }
}
