<?php

namespace Tests\Feature\Filament;

use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Each request in a test must get its panel's tenancy scope, as a real request
 * in its own process does. Otherwise a test that visits one panel and then the
 * other runs the second without tenancy and can pass while tenancy is broken.
 */
class PanelTenancyAcrossRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_second_panel_visited_in_a_test_is_still_tenant_scoped(): void
    {
        Gate::before(fn () => true);
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->create()->assignRole('super_admin');
        $mine = Team::factory()->create();
        $mine->users()->attach($user, ['role' => 'admin']);
        $user->forceFill(['current_team_id' => $mine->id])->save();
        $store = Store::factory()->create(['team_id' => $mine->id]);
        Product::factory()->create(['name' => 'Our Lamp', 'store_id' => $store->id, 'team_id' => $mine->id]);
        // Another team's product, in our store, so only the team scope can hide it.
        Product::factory()->create(['name' => 'Their Lamp', 'store_id' => $store->id, 'team_id' => Team::factory()->create()->id]);

        $this->actingAs($user);
        $this->get("/admin/{$mine->id}/products")->assertOk()->assertSee('Our Lamp')->assertDontSee('Their Lamp');
        $this->get("/app/{$mine->id}/products")->assertOk()->assertSee('Our Lamp')->assertDontSee('Their Lamp');
    }
}
