<?php

namespace App\Traits;

use App\Models\Store;
use App\Services\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the store the request resolved to.
 *
 * This is the control #939, #950 and #952 all describe the absence of. All three
 * are one root cause with three surfaces — the Blade storefront, the GraphQL
 * endpoint, the REST API — and each was previously going to be fixed at its own
 * call site. Scoping at the caller is the original failure: it has to be
 * remembered every time, and it was not.
 *
 * A global scope is remembered once. `withoutGlobalScope('store')` is the way
 * out where a query genuinely spans stores, and it has to be written down,
 * which is the point.
 *
 * In a panel the scope narrows to the stores the user's team owns — every one
 * of them, because a merchant in the panel is working across their business
 * rather than one shopfront. That is a second control, not the control: panel
 * *resources* are already Team-scoped by Filament tenancy. What it covers is
 * where that scoping does not reach — relation managers, widgets, custom pages,
 * and any bare `Model::query()` written in a panel.
 *
 * Off a host and off a panel — console, queues — the scope is inert, because
 * scoping to nothing there means an empty catalogue rather than a safe one.
 *
 * Requires `store_id` on the table. Applying it to a model without one produces
 * exactly the failure #958 was: a query naming a column that is not there.
 */
trait IsStoreScoped
{
    public static function bootIsStoreScoped(): void
    {
        static::addGlobalScope('store', function (Builder $query) {
            // Qualified, because this runs inside joins and subqueries where a
            // bare `store_id` is ambiguous.
            StoreContext::applyTo($query, $query->getModel()->getTable().'.store_id');
        });

        static::creating(function (Model $model) {
            $model->store_id ??= StoreContext::forWrites();
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
