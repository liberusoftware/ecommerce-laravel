<?php

namespace App\Traits;

use App\Models\Team;
use App\Services\StoreContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as belonging to a `Team`, and — from now on — writes it.
 *
 * The trait used to be a `team()` relation and nothing else. **No application
 * code wrote `team_id`**: the only writers were Filament's tenancy and a
 * `default(1)` on the column, so every row created by the API, a controller, a
 * seeder or a factory silently became team 1. The default did not fill a gap,
 * it hid one — and a tenant key that turns "nobody said" into "team 1" produces
 * rows nobody can later distinguish from rows that really are team 1's.
 *
 * The migration plan answered that with a backfill wave, because on a
 * deployment with real rows the damage is already done and the only question
 * left is which rows can be positively attributed. **This application has no
 * such deployment** — every database is built from the migrations — so the
 * correction belongs where the rows are created rather than in a migration that
 * tries to guess afterwards.
 *
 * So: the default is gone from the schema, and the write is here. A row that
 * nothing can attribute is left unstamped, which is a state an operator can see
 * and fix, unlike a row that quietly claims to be team 1's.
 *
 * `team_id` is deliberately absent from `$fillable` on every model that uses
 * this. The hook is its only writer here, so no request can post its way into
 * another merchant's tenancy.
 *
 * @see IsStoreScoped for the storefront-facing half — `store_id` is
 * the grain commerce reads on, and this key is derived from it.
 */
trait IsTenantModel
{
    public static function bootIsTenantModel(): void
    {
        static::creating(function (Model $model) {
            // `??=`, so Filament's own tenancy hook wins where it runs. In a
            // panel the tenant the user is looking at is the authority; this is
            // for every path that has no panel — the API, a controller, a
            // seeder, a queued job.
            $model->team_id ??= StoreContext::teamForWrites();
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
