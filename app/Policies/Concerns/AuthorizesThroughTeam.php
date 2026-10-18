<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Support\StoreAccess;
use App\Support\TeamPermission;
use Illuminate\Database\Eloquent\Model;

/**
 * Store data is authorised by the user's role in the record's own team, or by a
 * platform permission named after the Shield convention (`update_product::category`),
 * which applies to every store (see StoreAccess). Record-less abilities ask about
 * the current tenant or storefront.
 */
trait AuthorizesThroughTeam
{
    /** The permission area: catalogue, orders, customers or store. */
    abstract protected function area(): string;

    /** The model's Shield permission suffix, e.g. `product::category`. */
    abstract protected function platformSuffix(): string;

    protected function allows(User $user, string $team, string $ability, ?Model $record = null): bool
    {
        return StoreAccess::allows($user, $team, $record, $ability.'_'.$this->platformSuffix());
    }

    protected function manage(): string
    {
        return $this->area() === 'store' ? TeamPermission::STORE_MANAGE : $this->area().':manage';
    }

    protected function viewPermission(): string
    {
        return $this->area() === 'store' ? TeamPermission::STORE_MANAGE : $this->area().':view';
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, $this->viewPermission(), 'view_any');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->allows($user, $this->viewPermission(), 'view', $record);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, $this->manage(), 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, $this->manage(), 'update', $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, $this->manage(), 'delete', $record);
    }

    public function deleteAny(User $user): bool
    {
        return $this->allows($user, $this->manage(), 'delete_any');
    }

    public function restore(User $user, Model $record): bool
    {
        return $this->allows($user, $this->manage(), 'restore', $record);
    }

    public function restoreAny(User $user): bool
    {
        return $this->allows($user, $this->manage(), 'restore_any');
    }

    public function forceDelete(User $user, Model $record): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Model $record): bool
    {
        return $this->allows($user, $this->manage(), 'replicate', $record);
    }

    public function reorder(User $user): bool
    {
        return $this->allows($user, $this->manage(), 'reorder');
    }
}
