<?php

namespace App\Policies;

use App\Models\ShippingMethod;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;

/** Shipping methods are store configuration: the same people who manage the store's settings. */
class ShippingMethodPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->managesTeam($user, Filament::getTenant());
    }

    public function view(User $user, ShippingMethod $method): bool
    {
        return $this->managesStore($user, $method->store);
    }

    public function create(User $user): bool
    {
        return $this->managesTeam($user, Filament::getTenant());
    }

    public function update(User $user, ShippingMethod $method): bool
    {
        return $this->managesStore($user, $method->store);
    }

    public function delete(User $user, ShippingMethod $method): bool
    {
        return $this->managesStore($user, $method->store);
    }

    public function deleteAny(User $user): bool
    {
        return $this->managesTeam($user, Filament::getTenant());
    }

    private function managesStore(User $user, ?Store $store): bool
    {
        return $store !== null && (new StorePolicy)->manageSettings($user, $store);
    }

    private function managesTeam(User $user, mixed $team): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $team instanceof Team && ($user->ownsTeam($team)
            || $team->users()->whereKey($user->getKey())->wherePivot('role', 'admin')->exists());
    }
}
