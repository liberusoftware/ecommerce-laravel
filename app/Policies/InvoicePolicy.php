<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\AuthorizesThroughTeam;
use Illuminate\Database\Eloquent\Model;

/** Invoices are financial records: never deleted. */
class InvoicePolicy
{
    use AuthorizesThroughTeam;

    protected function area(): string
    {
        return 'orders';
    }

    protected function platformSuffix(): string
    {
        return 'invoice';
    }

    public function delete(User $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
