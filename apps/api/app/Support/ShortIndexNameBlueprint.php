<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * MySQL/MariaDB reject identifiers over 64 characters, while SQLite and PostgreSQL do not.
 * Names that fit are unchanged, so existing databases keep their index names; only longer
 * generated names are shortened deterministically (also for dropUnique/dropIndex by columns).
 */
class ShortIndexNameBlueprint extends Blueprint
{
    protected function createIndexName($type, array $columns)
    {
        $name = parent::createIndexName($type, $columns);

        return strlen($name) > 64 ? substr($name, 0, 55).'_'.substr(md5($name), 0, 8) : $name;
    }
}
