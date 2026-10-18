<?php

namespace App\Support;

use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Laravel\Jetstream\Jetstream;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The one answer to "may this user do this to this store's data?".
 *
 * Two layers. Platform: super_admin may do anything, and a platform permission
 * (a Spatie permission such as `update_order`, granted through a platform role)
 * applies to every store — that is what makes it a platform permission. Merchant:
 * a team role applies only inside that team; the owner holds every permission.
 * Nothing a user is granted in one team reaches another.
 */
final class StoreAccess
{
    /**
     * @param  Team|Store|Model|null  $scope  the record acted on; null means the
     *                                        storefront's store, else the panel tenant
     */
    public static function allows(?User $user, string $permission, mixed $scope = null, ?string $platformPermission = null): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->hasRole('super_admin') || ($platformPermission !== null && self::holdsPlatformPermission($user, $platformPermission))) {
            return true;
        }

        $team = self::teamOf($scope);

        return $team !== null && self::teamGrants($user, $team, $permission);
    }

    public static function authorize(?User $user, string $permission, mixed $scope = null, ?string $platformPermission = null): void
    {
        abort_unless(self::allows($user, $permission, $scope, $platformPermission), 403);
    }

    /** A platform-wide ability: super_admin, or the named platform permission. */
    public static function platform(?User $user, string $permission): bool
    {
        return $user !== null && ($user->hasRole('super_admin') || self::holdsPlatformPermission($user, $permission));
    }

    /** A permission that does not exist is one nobody holds, not an error. */
    public static function holdsPlatformPermission(User $user, string $permission): bool
    {
        try {
            return $user->hasPermissionTo($permission);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /** Whether the user's role in this team grants the permission. Owners hold every permission. */
    public static function teamGrants(User $user, Team $team, string $permission): bool
    {
        if ($user->ownsTeam($team)) {
            return true;
        }

        $role = $team->users()->whereKey($user->getKey())->first()?->membership?->role;
        $permissions = $role ? (Jetstream::findRole($role)?->permissions ?? []) : [];

        return in_array('*', $permissions, true)
            || in_array($permission, $permissions, true)
            || in_array(str_replace(':view', ':manage', $permission), $permissions, true);
    }

    public static function teamOf(mixed $scope): ?Team
    {
        if ($scope instanceof Team) {
            return $scope;
        }

        if ($scope instanceof Store) {
            return $scope->team;
        }

        if ($scope instanceof Model) {
            if (isset($scope->team_id)) {
                return Team::find($scope->team_id);
            }
            if (isset($scope->store_id)) {
                return Store::query()->find($scope->store_id)?->team;
            }

            return null;
        }

        $tenant = Store::current()?->team ?? Filament::getTenant();

        return $tenant instanceof Team ? $tenant : null;
    }
}
