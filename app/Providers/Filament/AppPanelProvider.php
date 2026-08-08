<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages;
use App\Filament\App\Pages\CreateTeam;
use App\Filament\App\Pages\EditProfile;
use App\Filament\App\Pages\EditTeam;
use App\Http\Middleware\TeamsPermission;
use App\Listeners\CreatePersonalTeam;
use App\Listeners\SwitchTeam;
use App\Models\Team;
use Filament\Actions\Action;
use Filament\Events\Auth\Registered;
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
            // No ->default() here: AdminPanelProvider already claims it, and
            // PanelRegistry::getDefault() takes Arr::first(), so admin won on
            // registration order alone while this panel also claimed the flag.
            // That made the fallback panel a function of provider order, and the
            // fallback is load-bearing — FilamentManager::isAuthorizationStrict()
            // reads getCurrentOrDefaultPanel(), so outside a panel (queues,
            // console, policy checks with no request) it reads the default. If
            // order ever flipped, strictAuthorization() below would have become
            // the global fallback and thrown on every policy-less Admin resource.
            ->id('app')
            ->path('app')
            // ->login([AuthenticatedSessionController::class, 'create'])
            // ->registration()
            // ->passwordReset()
            // ->emailVerification()
            // Without this, a resource with no policy is wide open: Filament's
            // get_authorization_response() returns allow() when no policy exists.
            // That is how ArticleResource and CollectionResource shipped with
            // unguarded CRUD. Strict mode throws instead, so the next policy-less
            // resource fails loudly in CI rather than silently granting everyone.
            //
            // Scoped to this panel deliberately: the Admin panel still has
            // policy-less resources (ChatConversation, CustomerSegment, Discount,
            // Menu, MenuItem, Page, TaxClass, User), so turning it on there would
            // throw across the back-office. Those want policies, not a flag.
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
         * Listen and create personal team for new accounts.
         */
        // Event::listen(
        //     Registered::class,
        //     CreatePersonalTeam::class,
        // );

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
