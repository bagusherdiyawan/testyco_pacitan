<?php

namespace App\Shared\Application;

final class ExportTime
{
    public static function valid(mixed $value): bool
    {
        return is_string($value) && strtotime($value) !== false
            && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9](?:\.[0-9]+)?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value)
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }
}
