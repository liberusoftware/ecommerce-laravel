<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Resources\MenuItemResource;
use App\Filament\Admin\Resources\MenuResource;
use App\Filament\Admin\Widgets\CustomerDemographicsWidget;
use App\Filament\Admin\Widgets\CustomerGrowthWidget;
use App\Filament\Admin\Widgets\InventoryStatsWidget;
use App\Filament\Admin\Widgets\LowStockInventoryWidget;
use App\Filament\Admin\Widgets\RecentOrdersWidget;
use App\Filament\Admin\Widgets\SalesOverviewWidget;
use App\Filament\Admin\Widgets\SalesTrendsChart;
use App\Filament\Admin\Widgets\TopProductsWidget;
use App\Filament\App\Pages;
use App\Filament\App\Pages\EditProfile;
use App\Http\Middleware\TeamsPermission;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Team;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource;
use Biostate\FilamentMenuBuilder\FilamentMenuBuilderPlugin;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages as FilamentPage;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Jetstream\Features;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // ->login([AuthenticatedSessionController::class, 'create'])
            // ->passwordReset()
            // ->emailVerification()
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => Color::Gray,
            ])
            ->userMenuItems([
                Action::make('profile')
                    ->label('Profile')
                    ->icon('heroicon-o-user-circle')
                    ->url(fn () => $this->shouldRegisterMenuItem()
                        ? url(EditProfile::getUrl())
                        : url($panel->getPath())),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
            ->pages([
                FilamentPage\Dashboard::class,
                EditProfile::class,
                // Pages\ApiTokenManagerPage::class,
            ])->widgets([
                AccountWidget::class,
                SalesOverviewWidget::class,
                SalesTrendsChart::class,
                TopProductsWidget::class,
                CustomerDemographicsWidget::class,
                CustomerGrowthWidget::class,
                InventoryStatsWidget::class,
                LowStockInventoryWidget::class,
                RecentOrdersWidget::class,
                // Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                TeamsPermission::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make()
                    ->navigationGroup('Administration'),
                FilamentMenuBuilderPlugin::make()
                    ->usingMenuModel(Menu::class)
                    ->usingMenuItemModel(MenuItem::class)
                    ->usingMenuResource(MenuResource::class)
                    ->usingMenuItemResource(MenuItemResource::class),
            ]);

        // if (Features::hasApiFeatures()) {
        //     $panel->userMenuItems([
        //         MenuItem::make()
        //             ->label('API Tokens')
        //             ->icon('heroicon-o-key')
        //             ->url(fn () => $this->shouldRegisterMenuItem()
        //                 ? url(Pages\ApiTokenManagerPage::getUrl())
        //                 : url($panel->getPath())),
        //     ]);
        // }

        if (Features::hasTeamFeatures()) {
            $panel
                ->tenant(Team::class, ownershipRelationship: 'team')
                ->tenantRegistration(Pages\CreateTeam::class)
                ->tenantProfile(Pages\EditTeam::class);
            //     ->userMenuItems([
            //         MenuItem::make()
            //             ->label('Team Settings')
            //             ->icon('heroicon-o-cog-6-tooth')
            //             ->url(fn () => $this->shouldRegisterMenuItem()
            //                 ? url(Pages\EditTeam::getUrl())
            //                 : url($panel->getPath())),
            //     ]);
        }

        return $panel;
    }

    public function boot(): void
    {
        /*
         * Shield's RoleResource is registered into this panel by its plugin, and
         * this panel is Team-tenanted on the `team` relationship. App\Models\Role
         * has no such relationship — `config/permission.php` sets 'teams' => false,
         * so roles are global here — and Filament throws a LogicException the
         * moment anything queries Role inside the panel. That is every page in
         * /admin, because the navigation resolves resources on each render.
         *
         * scopeToTenant(false) is Filament's own escape hatch for a resource that
         * is genuinely shared across tenants, which a global role is. It cannot be
         * set as a property here because the class belongs to the package.
         */
        RoleResource::scopeToTenant(false);
    }

    public function shouldRegisterMenuItem(): bool
    {
        return true; // auth()->user()?->hasVerifiedEmail() && Filament::hasTenancy() && Filament::getTenant();
    }
}
