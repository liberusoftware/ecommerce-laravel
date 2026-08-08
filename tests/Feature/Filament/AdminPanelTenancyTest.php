<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\Categories\Pages\ListCategories;
use App\Filament\Admin\Resources\ChatConversations\Pages\ListChatConversations;
use App\Filament\Admin\Resources\Coupons\Pages\ListCoupons;
use App\Filament\Admin\Resources\Discounts\Pages\ListDiscounts;
use App\Filament\Admin\Resources\Menus\Pages\ListMenus;
use App\Filament\Admin\Resources\Pages\Pages\ListPages;
use App\Filament\Admin\Resources\Products\Pages\ListProducts;
use App\Filament\Admin\Resources\Stores\Pages\ListStores;
use App\Filament\Admin\Resources\TaxClasses\Pages\ListTaxClasses;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The /admin panel is Team-tenant-scoped too (`ownershipRelationship: 'team'`),
 * which the sibling AppPanelTenancyTest covers for /app but nothing covered
 * here.
 *
 * That gap is what #958 is: `DiscountResource` and `MenuResource` are registered
 * in this tenant-scoped panel while `discounts` and `menus` carry no `team_id`.
 * Filament's `scopeEloquentQueryToTenant` sees a `BelongsTo` — the models do
 * declare `team()`, through `IsTenantModel` — and emits
 * `whereBelongsTo($tenant, 'team')`, so the query names a column that is not
 * there.
 *
 * Mounting every list page is the cheapest way to hold that shut for all ten
 * resources rather than the two somebody happened to notice.
 */
class AdminPanelTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function actingInAdminPanel(): Team
    {
        // Allow all authorization so this isolates the TENANCY wiring rather
        // than the Shield permission layer — same reasoning as AppPanelTenancyTest.
        Gate::before(fn () => true);
        Role::findOrCreate('super_admin', 'web');
        $user = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $team = $user->ownedTeams()->first();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($team);

        return $team;
    }

    public function test_every_admin_panel_list_page_mounts(): void
    {
        $this->actingInAdminPanel();

        $pages = [
            ListCategories::class, ListChatConversations::class, ListCoupons::class,
            ListDiscounts::class, ListMenus::class, ListPages::class,
            ListProducts::class, ListStores::class, ListTaxClasses::class,
            ListUsers::class,
        ];

        foreach ($pages as $page) {
            Livewire::test($page)->assertSuccessful();
        }
    }
}
