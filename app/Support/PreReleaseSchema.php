<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Migrations are edited in place before the first release, so a database built
 * before an edit never gets it. A migration that depends on an edit calls this
 * to stop with the rebuild instructions instead of running on a stale schema.
 */
final class PreReleaseSchema
{
    /** @param  array<string, list<string>>  $markers  table => columns an in-place edit added */
    public static function assertCurrent(array $markers): void
    {
        $missing = [];
        foreach ($markers as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasTable($table) && ! Schema::hasColumn($table, $column)) {
                    $missing[] = "{$table}.{$column}";
                }
            }
        }

        if ($missing !== []) {
            throw new RuntimeException(
                'This database was built from migrations that have since been consolidated (missing '.implode(', ', $missing).'). '
                .'It cannot be upgraded in place: rebuild it with `php artisan migrate:fresh --seed`, which drops every table. '
                .'See docs/INSTALLATION.md, "Rebuilding a development database".'
            );
        }
    }
}
