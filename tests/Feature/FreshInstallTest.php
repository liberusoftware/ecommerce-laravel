<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `migrate` then `db:seed` on a blank database must give an admin who can see
 * and manage the demo catalogue in both panels. Each panel is requested in its
 * own test: Filament boots one panel per process, so a second panel in the same
 * test would run without its tenancy scope.
 */
class FreshInstallTest extends TestCase
{
    use RefreshDatabase;

    private function install(): User
    {
        config(['seeding.admin_password' => 'a-known-password']);
        $this->seed(DatabaseSeeder::class);

        return User::where('email', 'admin@example.com')->firstOrFail();
    }

    /**
     * Read straight from the table: through the model, the panel's tenant
     * scope would apply to the expectation too and could return null.
     */
    private function firstName(string $table): string
    {
        $name = DB::table($table)->orderBy('id')->value('name');
        $this->assertIsString($name, "$table has no rows to look for.");

        return $name;
    }

    public function test_the_default_store_catalogue_and_admin_belong_to_the_default_team(): void
    {
        $admin = $this->install();
        $team = Team::where('name', 'default')->firstOrFail();

        $this->assertSame($team->id, (int) Store::where('slug', 'default')->value('team_id'));
        $this->assertSame($team->id, (int) $admin->current_team_id);
        $this->assertTrue($admin->belongsToTeam($team));

        foreach (['products', 'product_categories', 'collections', 'menus', 'menu_items'] as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "$table was not seeded.");
            $this->assertSame(0, DB::table($table)->where(fn ($q) => $q->whereNull('team_id')->orWhere('team_id', '!=', $team->id))->count(), "$table has rows outside the default team.");
        }
    }

    public function test_the_admin_panel_lists_the_seeded_catalogue(): void
    {
        $team = $this->install()->currentTeam;
        $this->actingAs(User::where('email', 'admin@example.com')->first());

        $this->get("/admin/{$team->id}/products")->assertOk()->assertSee($this->firstName('products'));
        $this->get("/admin/{$team->id}/categories")->assertOk()->assertSee($this->firstName('product_categories'));
        $this->get("/admin/{$team->id}/menus")->assertOk()->assertSee($this->firstName('menus'));
    }

    public function test_the_app_panel_lists_the_seeded_catalogue(): void
    {
        $team = $this->install()->currentTeam;
        $this->actingAs(User::where('email', 'admin@example.com')->first());

        $this->get("/app/{$team->id}/products")->assertOk()->assertSee($this->firstName('products'));
        $this->get("/app/{$team->id}/collections")->assertOk()->assertSee($this->firstName('collections'));
    }

    public function test_the_storefront_shows_the_seeded_catalogue(): void
    {
        $this->install();

        $this->get('/products')->assertOk()->assertSee($this->firstName('products'));
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        $this->install();
        $counts = fn () => collect(['users', 'teams', 'stores', 'products', 'product_categories', 'collections', 'menus', 'menu_items'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $before = $counts();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, $counts());
    }
}
