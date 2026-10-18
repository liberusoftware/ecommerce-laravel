<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Policies\Concerns\AuthorizesThroughTeam;
use Illuminate\Database\Eloquent\Model;

class OrderPolicy
{
    use AuthorizesThroughTeam;

    protected function area(): string
    {
        return 'orders';
    }

    protected function platformSuffix(): string
    {
        return 'order';
    }

    /** Only an order that never took payment; a pending one still holds reserved stock (cancel it first). */
    public function delete(User $user, Model $record): bool
    {
        return $this->allows($user, $this->manage(), 'delete', $record)
            && in_array($record->status, [Order::STATUS_FAILED, Order::STATUS_CANCELLED], true)
            && $record->payment_status !== 'paid';
    }
}
