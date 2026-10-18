<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages;
use App\Filament\App\Pages\CreateTeam;
use App\Filament\App\Pages\EditProfile;
use App\Filament\App\Pages\EditTeam;
use App\Http\Middleware\TeamsPermission;
use App\Listeners\SwitchTeam;
use App\Models\Team;
use Filament\Actions\Action;
use Filament\Events\TenantSet;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages\Dashboard;
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
use Illuminate\Support\Facades\Event;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Jetstream;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            // No ->default(): AdminPanelProvider claims it, and Filament falls back to the default panel off-panel.
            ->id('app')
            ->path('app')
            // ->login([AuthenticatedSessionController::class, 'create'])
            // ->registration()
            // ->passwordReset()
            // ->emailVerification()
            // A resource with no policy fails loudly instead of falling open. Both panels run strict.
            ->strictAuthorization()
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
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([
                Dashboard::class,
                EditProfile::class,
            ])
            // Filament/App/Widgets, not Filament/App/Widgets/Home — the Home
            // directory has never existed, so this discovered nothing and the one
            // widget that lived here was unreachable.
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->widgets([
                AccountWidget::class,
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
                TeamsPermission::class,
                Authenticate::class,
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
                ->tenantRegistration(CreateTeam::class)
                ->tenantProfile(EditTeam::class);
            // ->userMenuItems([
            //     MenuItem::make()
            //         ->label('Team Settings')
            //         ->icon('heroicon-o-cog-6-tooth')
            //         ->url(fn () => $this->shouldRegisterMenuItem()
            //             ? url(Pages\EditTeam::getUrl())
            //             : url($panel->getPath())),
            // ]);
        }

        return $panel;
    }

    public function boot(): void
    {
        /**
         * Disable Fortify routes.
         */
        // Fortify::$registersRoutes = false;

        /**
         * Disable Jetstream routes.
         */
        // Jetstream::$registersRoutes = false;

        /**
         * Listen and switch team if tenant was changed.
         */
        Event::listen(
            TenantSet::class,
            SwitchTeam::class,
        );
    }

    public function shouldRegisterMenuItem(): bool
    {
        return true; // auth()->user()?->hasVerifiedEmail() && Filament::hasTenancy() && Filament::getTenant();
    }
}
