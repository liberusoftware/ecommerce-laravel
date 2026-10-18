<?php

namespace App\Policies;

use App\Models\ProductRating;
use App\Models\ProductReview;
use App\Models\User;
use App\Policies\Concerns\AuthorizesThroughTeam;
use Illuminate\Database\Eloquent\Model;

class CustomerPolicy
{
    use AuthorizesThroughTeam;

    protected function area(): string
    {
        return 'customers';
    }

    protected function platformSuffix(): string
    {
        return 'customer';
    }

    /** A customer's orders, invoices and reviews outlive them; personal data goes through GDPR erasure. */
    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, $this->manage(), 'delete', $record)
            && ! $record->orders()->exists()
            && ! $record->invoices()->exists()
            && ! ProductReview::withoutGlobalScopes()->where('customer_id', $record->id)->exists()
            && ! ProductRating::withoutGlobalScopes()->where('customer_id', $record->id)->exists();
    }
}
