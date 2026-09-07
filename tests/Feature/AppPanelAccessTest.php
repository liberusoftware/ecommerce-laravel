<?php

namespace Tests\Feature;

use App\Actions\Fortify\CreateNewUser;
use App\Filament\App\Resources\Collections\CollectionResource;
use App\Filament\App\Resources\Orders\OrderResource;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Registration and /app access are one problem, not two.
 *
 * Registration was broken — CreateNewUser assigned a `staff` role that no seeder
 * creates (RolesSeeder dropped it in cf711cb without touching the caller), and on
 * an unseeded database Team::first() returned null and fatally errored first. That
 * bug was the only thing keeping strangers out of /app, because canAccessPanel
 * ended `return true; // TODO` and assignOrCreateTeam attached every registrant to
 * Team::first() — the merchant's own team.
 *
 * So fixing registration alone would have opened the door. These tests pin both
 * halves together: a registrant gets no team and no role, and /app requires a team.
 */
class AppPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'password' => 'Sup3rSecret!Password',
            'password_confirmation' => 'Sup3rSecret!Password',
        ], $overrides);
    }

    #[Test]
    public function registration_succeeds(): void
    {
        $user = (new CreateNewUser)->create($this->payload());

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('ada@example.test', $user->email);
    }

    /**
     * The heart of it. A shopper signing up must not become a member of the
     * merchant's team — assignOrCreateTeam used to attach them to Team::first().
     */
    #[Test]
    public function registration_does_not_put_a_shopper_in_the_stores_team(): void
    {
        $storeTeam = Team::factory()->create(['name' => 'The Store', 'personal_team' => false]);

        $user = (new CreateNewUser)->create($this->payload());

        $this->assertSame(0, $user->allTeams()->count(), 'A registrant must not be attached to any team.');
        $this->assertFalse($user->belongsToTeam($storeTeam), 'A registrant must never land inside the store team.');
    }

    #[Test]
    public function registration_grants_no_roles(): void
    {
        $user = (new CreateNewUser)->create($this->payload());

        $this->assertCount(0, $user->getRoleNames(), 'A shopper is not staff.');
    }

    /** The gate that the registration bug was standing in for. */
    #[Test]
    public function a_user_with_no_team_cannot_reach_the_app_panel(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('app')));
    }

    #[Test]
    public function a_team_member_can_reach_the_app_panel(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('app')));
    }

    #[Test]
    public function only_a_super_admin_can_reach_the_admin_panel(): void
    {
        Role::findOrCreate('super_admin', 'web');

        $plain = User::factory()->withPersonalTeam()->create();
        $this->actingAs($plain);
        $this->assertFalse($plain->canAccessPanel(Filament::getPanel('admin')));

        $admin = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $this->actingAs($admin);
        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
    }

    /**
     * Every app-panel resource denies a user who holds no permissions.
     *
     * Collection is in this list now. It used to be the exception, alongside
     * Article: no policy, and strictAuthorization off, so Filament's
     * authorization helper returned allow() and any team member could CRUD the
     * tenant's collections, price included. Closing it needed policies AND
     * seeded permissions together — the Shield set had no collection entries at
     * all, so a policy on its own would have denied super_admin too.
     * AppPanelAuthorizationTest covers it in detail; it is here so this file's
     * list is the whole panel rather than a subset.
     *
     * Article was the other half of that pair and no longer exists — its model,
     * resource and two-column table were deleted rather than moved to the CMS
     * that owns them, because there was nothing in them to move. See ADR 0012.
     */
    #[Test]
    public function permission_backed_resources_deny_a_user_without_permissions(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user);

        foreach ([
            ProductResource::class,
            OrderResource::class,
            CollectionResource::class,
        ] as $resource) {
            $this->assertFalse($resource::canViewAny(), $resource.' must not be viewable without permission.');
            $this->assertFalse($resource::canCreate(), $resource.' must not be creatable without permission.');
            $this->assertFalse($resource::canDeleteAny(), $resource.' must not be deletable without permission.');
        }
    }
}
