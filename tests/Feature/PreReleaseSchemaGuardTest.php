<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * A database migrated before the in-place consolidation must not be migrated
 * onward as if it were current: migrating stops, says why, and writes nothing.
 */
class PreReleaseSchemaGuardTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = '2026_10_23_000000_refuse_a_pre_release_schema';

    private const OPTION_TABLES = '2026_10_23_000001_create_product_option_tables';

    private const VARIANT_TABLE = '2022_09_26_113709_create_product_variants_table';

    private function guard(): object
    {
        return require database_path('migrations/'.self::GUARD.'.php');
    }

    public function test_a_fresh_schema_passes(): void
    {
        $this->guard()->up();

        $this->assertDatabaseHas('migrations', ['migration' => self::GUARD]);
    }

    public function test_migrating_a_stale_database_stops_before_writing_anything(): void
    {
        // A database as it stood before this branch: option tables never made,
        // cart lines and order lines without the columns added in place.
        Schema::dropIfExists('product_option_group_product');
        Schema::dropIfExists('product_option_choices');
        Schema::dropIfExists('product_option_groups');
        Schema::table('cart_items', fn ($table) => $table->dropColumn('configuration_key'));
        DB::table('migrations')->whereIn('migration', [self::GUARD, self::OPTION_TABLES])->delete();

        try {
            Artisan::call('migrate', ['--force' => true]);
            $this->fail('Migrating a stale database did not stop.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cart_items.configuration_key', $e->getMessage());
            $this->assertStringContainsString('migrate:fresh', $e->getMessage());
        }

        $this->assertFalse(Schema::hasTable('product_option_groups'));
        $this->assertDatabaseMissing('migrations', ['migration' => self::OPTION_TABLES]);
    }

    public function test_a_database_from_before_variants_stops_at_the_variant_table(): void
    {
        // Migrated after the guard ran, before variants: the in-place columns are
        // missing, and only the new file would run.
        Schema::disableForeignKeyConstraints();
        Schema::table('cart_items', fn ($table) => $table->dropConstrainedForeignId('product_variant_id'));
        Schema::table('order_items', fn ($table) => $table->dropConstrainedForeignId('product_variant_id'));
        Schema::drop('product_variants');
        Schema::enableForeignKeyConstraints();
        DB::table('migrations')->where('migration', self::VARIANT_TABLE)->delete();

        try {
            Artisan::call('migrate', ['--force' => true]);
            $this->fail('Migrating a database from before variants did not stop.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cart_items.product_variant_id', $e->getMessage());
            $this->assertStringContainsString('order_items.product_variant_id', $e->getMessage());
            $this->assertStringContainsString('migrate:fresh', $e->getMessage());
        }

        $this->assertFalse(Schema::hasTable('product_variants'));
        $this->assertDatabaseMissing('migrations', ['migration' => self::VARIANT_TABLE]);
    }
}
