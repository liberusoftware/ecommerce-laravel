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
 * Opening a team in a panel makes it the user's current team, which is what
 * the store scope reads. One panel per test: Filament boots one per process.
 */
class PanelTeamSwitchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Team, 2: Team} */
    private function memberOfTwoTeams(): array
    {
        Gate::before(fn () => true);
        Role::findOrCreate('super_admin', 'web');

        $user = User::factory()->create()->assignRole('super_admin');

        foreach (['Alpha', 'Beta'] as $name) {
            $team = Team::factory()->create(['name' => $name]);
            $team->users()->attach($user, ['role' => 'admin']);
            $store = Store::factory()->create(['team_id' => $team->id]);
            Product::factory()->create(['name' => "{$name} Lamp", 'store_id' => $store->id, 'team_id' => $team->id]);
            $teams[] = $team;
        }

        $user->forceFill(['current_team_id' => $teams[0]->id])->save();
        $this->actingAs($user);

        return [$user, ...$teams];
    }

    public function test_opening_a_team_in_the_app_panel_switches_to_it(): void
    {
        [$user, , $beta] = $this->memberOfTwoTeams();

        $this->get("/app/{$beta->id}/products")->assertOk()->assertSee('Beta Lamp')->assertDontSee('Alpha Lamp');

        $this->assertSame($beta->id, (int) $user->fresh()->current_team_id);
    }

    public function test_a_team_the_user_does_not_belong_to_is_refused_and_not_switched_to(): void
    {
        [$user, $alpha] = $this->memberOfTwoTeams();
        $stranger = Team::factory()->create();

        $this->get("/app/{$stranger->id}/products")->assertNotFound();

        $this->assertSame($alpha->id, (int) $user->fresh()->current_team_id);
    }
}
