<?php

namespace Tests\Feature\Filament;

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The /app Filament panel is Team-tenant-scoped (ownershipRelationship: 'team').
 * Every resource model therefore needs a team() relationship and a team_id column,
 * and the tenant owner must be able to access the tenant. These tests mount each
 * resource's list page under a tenant to prove the panel is wired end to end.
 */
class AppPanelTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function actingInTeamPanel(): Team
    {
        // Allow all authorization so these tests isolate the TENANCY wiring, not the
        // Shield permission layer (which gates resources on the admin panel separately).
        Gate::before(fn () => true);
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $team = $user->ownedTeams()->first();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);

        return $team;
    }

    public function test_team_owner_can_access_their_tenant(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->ownedTeams()->first();

        $this->assertTrue($user->canAccessTenant($team));
        $this->assertTrue($user->getTenants(Filament::getPanel('app'))->contains($team));
    }

    /**
     * Over HTTP rather than through `Livewire::test`, for the reason the admin
     * sibling gives at length: the tenant scope is a global scope registered
     * when the panel boots, and the panel boots in middleware. Mounting a page
     * directly skips all of it, so the page renders clean whether or not the
     * scope it is supposed to run under would survive. This test passed for
     * months against a panel with no tenant scoping at all.
     */
    public function test_every_app_panel_list_page_responds(): void
    {
        $team = $this->actingInTeamPanel();

        $resources = Filament::getPanel('app')->getResources();

        $this->assertNotEmpty($resources, 'The app panel registered no resources — the enumeration is wrong.');

        $failures = [];

        foreach ($resources as $resource) {
            if (! $resource::hasPage('index')) {
                continue;
            }

            $response = $this->get($resource::getUrl(tenant: $team));

            if ($response->getStatusCode() >= 300) {
                $failures[] = class_basename($resource).' -> '.$response->getStatusCode()
                    .($response->exception ? ' :: '.$response->exception::class.': '.$response->exception->getMessage() : '');
            }
        }

        sort($failures);

        $this->assertSame([], $failures, "App panel list pages that do not respond 2xx:\n".implode("\n", $failures));
    }
}
