<?php

namespace App\Providers;

use App\Actions\Jetstream\AddTeamMember;
use App\Actions\Jetstream\CreateTeam;
use App\Actions\Jetstream\DeleteTeam;
use App\Actions\Jetstream\DeleteUser;
use App\Actions\Jetstream\InviteTeamMember;
use App\Actions\Jetstream\RemoveTeamMember;
use App\Actions\Jetstream\UpdateTeamName;
use App\Support\TeamPermission;
use Illuminate\Support\ServiceProvider;
use Laravel\Jetstream\Jetstream;

class JetstreamServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePermissions();

        Jetstream::createTeamsUsing(CreateTeam::class);
        Jetstream::updateTeamNamesUsing(UpdateTeamName::class);
        Jetstream::addTeamMembersUsing(AddTeamMember::class);
        Jetstream::inviteTeamMembersUsing(InviteTeamMember::class);
        Jetstream::removeTeamMembersUsing(RemoveTeamMember::class);
        Jetstream::deleteTeamsUsing(DeleteTeam::class);
        Jetstream::deleteUsersUsing(DeleteUser::class);
    }

    /**
     * Configure the roles and permissions that are available within the application.
     */
    protected function configurePermissions(): void
    {
        Jetstream::defaultApiTokenPermissions(['read']);

        // Per-team roles. The owner holds every permission in their own team; nothing here reaches beyond it.
        Jetstream::role(TeamPermission::ROLE_ADMIN, 'Administrator', ['*'])
            ->description('Runs the store: catalogue, orders, customers, settings and team members.');
        Jetstream::role(TeamPermission::ROLE_CATALOGUE, 'Catalogue manager', [
            TeamPermission::CATALOGUE_VIEW, TeamPermission::CATALOGUE_MANAGE, TeamPermission::ORDERS_VIEW,
        ])->description('Manages products, categories, collections and reviews.');
        Jetstream::role(TeamPermission::ROLE_ORDERS, 'Order manager', [
            TeamPermission::ORDERS_VIEW, TeamPermission::ORDERS_MANAGE, TeamPermission::CUSTOMERS_VIEW,
            TeamPermission::CUSTOMERS_MANAGE, TeamPermission::CATALOGUE_VIEW,
        ])->description('Fulfils orders, issues refunds and looks after customers.');
        Jetstream::role(TeamPermission::ROLE_VIEWER, 'Viewer', [
            TeamPermission::CATALOGUE_VIEW, TeamPermission::ORDERS_VIEW, TeamPermission::CUSTOMERS_VIEW,
        ])->description('Can see the store\'s catalogue, orders and customers, and change nothing.');

    }
}
