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

    public function test_every_team_scoped_table_has_a_store_id(): void
    {
        $missing = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? explode('.', $table)[1] : $table;

            if (in_array($table, self::NOT_COMMERCE, true)) {
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
