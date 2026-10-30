<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The suite runs on SQLite, which accepts identifiers MySQL refuses. A name
 * Laravel derives from long table and column names can pass here and stop a
 * fresh MySQL install.
 */
class SchemaPortabilityTest extends TestCase
{
    use RefreshDatabase;

    private const MYSQL_IDENTIFIER_LIMIT = 64;

    public function test_every_index_name_fits_mysqls_identifier_limit(): void
    {
        $tooLong = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (strlen($index['name']) > self::MYSQL_IDENTIFIER_LIMIT) {
                    $tooLong[] = "{$table}: {$index['name']}";
                }
            }
        }

        $this->assertSame([], $tooLong);
    }
}
