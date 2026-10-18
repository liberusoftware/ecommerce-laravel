<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\StoreAccess;
use Illuminate\Database\Eloquent\Model;

/** Platform content: super_admin, or a platform permission named after the Shield convention. */
trait AuthorizesPlatformWide
{
    /** The model's Shield permission suffix, e.g. `tax::class`. */
    abstract protected function platformSuffix(): string;

    protected function holds(User $user, string $ability): bool
    {
        return $user->hasRole('super_admin') || StoreAccess::holdsPlatformPermission($user, $ability.'_'.$this->platformSuffix());
    }

    public function viewAny(User $user): bool
    {
        return $this->holds($user, 'view_any');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->holds($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->holds($user, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->holds($user, 'update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->holds($user, 'delete');
    }

    public function deleteAny(User $user): bool
    {
        return $this->holds($user, 'delete_any');
    }

    public function reorder(User $user): bool
    {
        return $this->holds($user, 'reorder');
    }
}
