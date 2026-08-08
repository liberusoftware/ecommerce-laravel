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
 * Off a resolved host — panels, console, queues — the scope is inert. It is not
 * the only control in those contexts: panels are Team-scoped by Filament
 * tenancy, and the API write paths check ownership directly.
 *
 * Requires `store_id` on the table. Applying it to a model without one produces
 * exactly the failure #958 was: a query naming a column that is not there.
 */
trait IsStoreScoped
{
    public static function bootIsStoreScoped(): void
    {
        static::addGlobalScope('store', function (Builder $query) {
            $storeId = StoreContext::forReads();

            if ($storeId === null) {
                return;
            }

            // Qualified, because this runs inside joins and subqueries where a
            // bare `store_id` is ambiguous.
            $query->where($query->getModel()->getTable().'.store_id', $storeId);
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
