<?php

namespace Tests\Feature;

use App\Actions\Jetstream\CreateTeam;
use App\Filament\Admin\Resources\Teams\Pages\CreateTeam as AdminCreateTeam;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Support\Installation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Each team has one store by default. The store has no channel, so it is not
 * public until the platform maps a domain to it.
 */
class StoreProvisioningTest extends TestCase
{
    use RefreshDatabase;

    /** Jetstream's CreateTeam authorizes through TeamPolicy::create, which checks create_store. */
    private function createTeamAs(User $user, string $name): Team
    {
        $user->givePermissionTo(Permission::findOrCreate('create_store', 'web'));

        return (new CreateTeam)->create($user, ['name' => $name]);
    }

    public function test_a_new_team_gets_its_store_in_saas_and_marketplace_modes(): void
    {
        foreach ([Installation::SAAS, Installation::MARKETPLACE] as $mode) {
            config(['ecommerce.installation.mode' => $mode]);

            $team = $this->createTeamAs(User::factory()->create(), "Acme {$mode}");

            $store = $team->stores()->sole();
            $this->assertSame("Acme {$mode}", $store->name);
            $this->assertSame(0, $store->channels()->count());
        }
    }

    public function test_a_single_store_installation_creates_no_store_for_a_new_team(): void
    {
        config(['ecommerce.installation.mode' => Installation::SINGLE]);

        $team = $this->createTeamAs(User::factory()->create(), 'Staff');

        $this->assertSame(0, $team->stores()->count());
    }

    public function test_a_team_created_by_the_platform_administrator_gets_its_store(): void
    {
        config(['ecommerce.installation.mode' => Installation::SAAS]);
        $role = Role::findOrCreate('super_admin', 'web');
        $role->givePermissionTo(Permission::findOrCreate('create_store', 'web'), Permission::findOrCreate('view_any_store', 'web'));
        $admin = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($admin->ownedTeams()->first());
        Filament::getPanel('admin')->boot();

        Livewire::test(AdminCreateTeam::class)
            ->fillForm(['name' => 'Acme', 'user_id' => User::factory()->create()->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Team::where('name', 'Acme')->sole()->stores()->count());
        $this->assertSame(2, Store::count());
    }
}
