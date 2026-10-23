<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Collections\CollectionResource;
use App\Filament\App\Resources\Customers\CustomerResource;
use App\Filament\App\Resources\FacebookConnections\FacebookConnectionResource;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Filament\App\Resources\Orders\OrderResource;
use App\Filament\App\Resources\ProductCategories\ProductCategoryResource;
use App\Filament\App\Resources\ProductOptionGroups\ProductOptionGroupResource;
use App\Filament\App\Resources\ProductRatings\ProductRatingResource;
use App\Filament\App\Resources\ProductReviews\ProductReviewResource;
use App\Filament\App\Resources\Products\ProductResource;
use App\Filament\App\Resources\ShippingMethods\ShippingMethodResource;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Merchant-panel access comes from the user's role in the tenant team; the owner
 * holds everything in their own team. A platform permission is platform-wide by
 * design (see PlatformPanelTest), so it grants only the ability it names.
 */
class AppPanelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{class-string, string}> resource => the permission area it needs */
    public static function resources(): array
    {
        return [
            'products' => [ProductResource::class, 'catalogue'],
            'categories' => [ProductCategoryResource::class, 'catalogue'],
            'option groups' => [ProductOptionGroupResource::class, 'catalogue'],
            'collections' => [CollectionResource::class, 'catalogue'],
            'reviews' => [ProductReviewResource::class, 'catalogue'],
            'ratings' => [ProductRatingResource::class, 'catalogue'],
            'orders' => [OrderResource::class, 'orders'],
            'invoices' => [InvoiceResource::class, 'orders'],
            'customers' => [CustomerResource::class, 'customers'],
            'shipping' => [ShippingMethodResource::class, 'store'],
            'facebook' => [FacebookConnectionResource::class, 'store'],
        ];
    }

    /** Which areas each team role may manage and view. */
    private const ROLES = [
        'admin' => ['manage' => ['catalogue', 'orders', 'customers', 'store'], 'view' => ['catalogue', 'orders', 'customers', 'store']],
        'catalogue' => ['manage' => ['catalogue'], 'view' => ['catalogue', 'orders']],
        'orders' => ['manage' => ['orders', 'customers'], 'view' => ['orders', 'customers', 'catalogue']],
        'viewer' => ['manage' => [], 'view' => ['catalogue', 'orders', 'customers']],
    ];

    private function inTeam(Team $team, User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($team);
    }

    #[Test]
    #[DataProvider('resources')]
    public function the_team_owner_manages_everything_in_their_own_team(string $resource, string $area): void
    {
        $owner = User::factory()->create();
        $this->inTeam(Team::factory()->create(['user_id' => $owner->id]), $owner);

        $this->assertTrue($resource::canViewAny());
        $this->assertTrue($resource::canCreate());
    }

    #[Test]
    #[DataProvider('resources')]
    public function each_team_role_gets_only_what_it_grants(string $resource, string $area): void
    {
        foreach (self::ROLES as $role => $grants) {
            $team = Team::factory()->create();
            $member = User::factory()->create();
            $team->users()->attach($member, ['role' => $role]);
            $this->inTeam($team, $member);

            $this->assertSame(in_array($area, $grants['view'], true) && ($area !== 'store' || $role === 'admin'), $resource::canViewAny(), "{$role} viewAny on {$resource}");
            $this->assertSame(in_array($area, $grants['manage'], true), $resource::canCreate(), "{$role} manage on {$resource}");
        }
    }

    #[Test]
    #[DataProvider('resources')]
    public function a_member_of_another_team_gets_nothing_here(string $resource, string $area): void
    {
        $member = User::factory()->create();
        Team::factory()->create()->users()->attach($member, ['role' => 'admin']);
        $this->inTeam(Team::factory()->create(), $member);

        $this->assertFalse($resource::canViewAny());
        $this->assertFalse($resource::canCreate());
    }

    #[Test]
    #[DataProvider('resources')]
    public function a_platform_permission_for_something_else_grants_nothing_here(string $resource, string $area): void
    {
        $user = User::factory()->create();
        foreach (['create_store', 'view_any_page', 'access_admin_panel'] as $name) {
            $user->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        $team = Team::factory()->create();
        $team->users()->attach($user, ['role' => 'viewer']);
        $this->inTeam($team, $user);

        $this->assertFalse($resource::canCreate());
    }

    #[Test]
    #[DataProvider('resources')]
    public function the_platform_super_admin_manages_everything(string $resource, string $area): void
    {
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $this->inTeam($admin->ownedTeams()->first(), $admin);

        $this->assertTrue($resource::canViewAny());
        $this->assertTrue($resource::canCreate());
    }

    #[Test]
    public function owning_or_administering_a_team_never_reaches_the_platform_panel(): void
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);
        $admin = User::factory()->create();
        $team->users()->attach($admin, ['role' => 'admin']);

        foreach ([$owner, $admin] as $user) {
            $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
            $this->assertTrue($user->canAccessPanel(Filament::getPanel('app')));
            $this->actingAs($user)->get('/admin')->assertForbidden();
        }
    }
}
