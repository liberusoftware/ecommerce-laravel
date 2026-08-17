<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wave 1.5, step 2: every team-scoped commerce table carries a `store_id`, and
 * it is derived from the team that owns the row.
 *
 * The scope in step 3 reads this column. A scope over a column that is empty,
 * or filled with a constant that lumps merchants together, is worse than no
 * scope — it looks like a control.
 */
class StoreBackfillTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `team_id` on these is the membership graph itself, which is Team-grained
     * by definition. `stores.team_id` is the ownership edge the derivation reads.
     */
    private const NOT_COMMERCE = ['teams', 'team_user', 'team_invitations', 'stores'];

    /**
     * Team-scoped, and not store-scoped on purpose — for now.
     *
     * `menus` and `menu_items` are read on the storefront by the menu builder's
     * own Blade component, which queries `Biostate\FilamentMenuBuilder\Models\Menu`
     * by class name rather than the model this application configures. A store
     * scope on `App\Models\Menu` would therefore control the panel and leave the
     * storefront reading exactly what it reads today, which is a control that
     * looks like one — the thing this whole wave is written against.
     *
     * `discounts` has no storefront read at all: the only reference outside the
     * panel is `CustomerGroup::discounts()`. Checkout discounts run through
     * `Coupon`, which is store-scoped already.
     *
     * Per-storefront navigation is a product change, not a backfill, and it sits
     * with wave 2's other grain corrections next to `coupons.code`.
     */
    private const NOT_STORE_GRAINED_YET = [
        'discounts', 'menus', 'menu_items',

        // The eight roots, which took `team_id` when `IsTenantModel` started
        // writing the key. Team-grained today and read by nothing on a
        // storefront, so a store scope on them would be a control over a path
        // nobody walks. Several have an obvious store grain waiting —
        // `seo_settings` and `inventory_locations` most of all — and each gets
        // it when something reads it per storefront, which is the same rule the
        // rest of this wave followed.
        'ab_tests', 'cart_recovery_campaigns', 'customer_groups', 'customer_segments',
        'inventory_locations', 'recommendation_rules', 'seo_settings', 'taxonomy_categories',

        // A Meta Business belongs to the merchant, not to one of their
        // shopfronts, and #808 asked for a connection per team. Nothing on a
        // storefront reads it — the catalogue push is a queued job — so a store
        // scope here would control a path nobody walks. It gets a store grain
        // if a merchant ever needs two Catalogs.
        'facebook_connections',
    ];

    public function test_every_team_scoped_table_has_a_store_id(): void
    {
        $missing = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? explode('.', $table)[1] : $table;

            if (in_array($table, self::NOT_COMMERCE, true) || in_array($table, self::NOT_STORE_GRAINED_YET, true)) {
                continue;
            }

            if (Schema::hasColumn($table, 'team_id') && ! Schema::hasColumn($table, 'store_id')) {
                $missing[] = $table;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'These tables are team-scoped but carry no store_id, so the storefront scope cannot reach them:',
            ...$missing,
        ]));
    }

    /**
     * The derivation itself, run against rows that exist — which the migration
     * cannot be observed doing on a fresh database, because there are none.
     *
     * Re-running `up()` is safe by construction: it only fills rows whose
     * `store_id` is null, and only creates stores for teams that have none.
     */
    public function test_a_row_is_backfilled_with_its_own_teams_store(): void
    {
        $mine = Team::factory()->create(['user_id' => User::factory()->create()->id]);
        $theirs = Team::factory()->create(['user_id' => User::factory()->create()->id]);

        $myProduct = Product::factory()->create(['team_id' => $mine->id]);
        $theirProduct = Product::factory()->create(['team_id' => $theirs->id]);

        // Both teams were created after the migration ran, so neither has a
        // store and neither product has a store_id — the state a deployment is
        // in when the migration arrives.
        DB::table('products')->whereIn('id', [$myProduct->id, $theirProduct->id])->update(['store_id' => null]);

        $this->runTheBackfill();

        $mineStoreId = DB::table('products')->where('id', $myProduct->id)->value('store_id');
        $theirsStoreId = DB::table('products')->where('id', $theirProduct->id)->value('store_id');

        $this->assertNotNull($mineStoreId, 'The product was left with no store, so nothing can scope it.');
        $this->assertNotSame(
            $mineStoreId,
            $theirsStoreId,
            'Two merchants were given the same store, which is the mis-attribution this is meant to avoid.',
        );
        $this->assertSame($mine->id, (int) DB::table('stores')->where('id', $mineStoreId)->value('team_id'));
        $this->assertSame($theirs->id, (int) DB::table('stores')->where('id', $theirsStoreId)->value('team_id'));
    }

    public function test_a_row_belonging_to_no_team_is_left_alone(): void
    {
        $orphan = Product::factory()->create(['team_id' => null]);
        DB::table('products')->where('id', $orphan->id)->update(['store_id' => null]);

        $this->runTheBackfill();

        $this->assertNull(
            DB::table('products')->where('id', $orphan->id)->value('store_id'),
            'A row that belongs to nobody was given a store, which is how default(1) started.',
        );
    }

    private function runTheBackfill(): void
    {
        $migration = require database_path('migrations/2026_08_08_000002_add_store_id_to_team_scoped_tables.php');

        $migration->up();
    }
}
