<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Ownership checks for the admin-gated API writes.
 *
 * `admin` and `super_admin` are global roles, not per-team ones —
 * `config/permission.php` sets `'teams' => false` — so the role check these
 * endpoints already perform says "this person administers something", never
 * "this person administers *this*". An admin of one merchant could edit and
 * soft-delete another merchant's catalogue (#939).
 *
 * This closes the write half. The read half is not fixed here, and cannot be
 * fixed the same way: a scope keyed off the actor's teams would return nothing
 * to a shopper, who is an authenticated user with no team at all. Reads need the
 * tenant of the *request* rather than of the actor, which is what wave 1.5's
 * Store/Channel resolution provides.
 */
trait OwnsTeamResources
{
    /**
     * Teams the actor can act in — owned and joined, which is what
     * `canAccessPanel` and the Filament tenant switcher already mean by it.
     *
     * @return list<int>
     */
    protected function actorTeamIds(Request $request): array
    {
        return $request->user()
            ?->allTeams()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all() ?? [];
    }

    /**
     * 404 unless the resource belongs to a team the actor is in.
     *
     * 404 rather than 403, matching `ReturnRequestController::show`: a foreign
     * record should not be distinguishable from one that does not exist.
     *
     * A resource with no team is left alone. Nothing creates one — every write
     * path here stamps a team — so this only concerns rows that predate the
     * column, which belong to nobody rather than to somebody else.
     */
    protected function assertActorOwns(Request $request, Model $resource): void
    {
        if ($resource->team_id === null) {
            return;
        }

        abort_unless(in_array((int) $resource->team_id, $this->actorTeamIds($request), true), 404);
    }

    /**
     * The team a resource created through this request should belong to.
     *
     * The actor's current team, falling back to any team they are in. Null when
     * they are in none, in which case the caller leaves the column alone and the
     * database default stands — the same row that would have been written before
     * this change.
     */
    protected function creationTeamId(Request $request): ?int
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return $user->current_team_id ?? $this->actorTeamIds($request)[0] ?? null;
    }
}
