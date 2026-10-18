<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\Teams\Pages\CreateTeam;
use App\Filament\Admin\Resources\Teams\Pages\EditTeam;
use App\Models\Order;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminTeamResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::findOrCreate('super_admin', 'web');
        foreach (['view_any_store', 'view_store', 'create_store', 'update_store', 'delete_store'] as $permission) {
            $role->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $this->admin = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->admin->ownedTeams()->first());
        Filament::getPanel('admin')->boot();
    }

    public function test_a_team_is_created_with_its_owner(): void
    {
        $owner = User::factory()->create();

        Livewire::test(CreateTeam::class)
            ->fillForm(['name' => 'Acme', 'user_id' => $owner->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($owner->id, (int) Team::where('name', 'Acme')->value('user_id'));
    }

    public function test_a_team_needs_an_owner(): void
    {
        Livewire::test(CreateTeam::class)
            ->fillForm(['name' => 'Acme'])
            ->call('create')
            ->assertHasFormErrors(['user_id']);
    }

    public function test_a_team_with_orders_cannot_be_deleted(): void
    {
        $team = Team::factory()->create();
        Order::forceCreate(['team_id' => $team->id, 'total_amount' => 10, 'status' => 'paid', 'payment_status' => 'paid', 'order_date' => now()]);

        $this->assertFalse($this->admin->can('delete', $team));
        Livewire::test(EditTeam::class, ['record' => $team->getRouteKey()])->assertActionHidden(DeleteAction::class);
        $this->assertNotNull($team->fresh());
    }

    public function test_a_team_without_financial_records_can_be_deleted(): void
    {
        $team = Team::factory()->create();

        Livewire::test(EditTeam::class, ['record' => $team->getRouteKey()])->callAction(DeleteAction::class);

        $this->assertNull($team->fresh());
    }
}
