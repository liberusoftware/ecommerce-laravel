<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which store the current request is about.
 *
 * Reads and writes ask different questions, and conflating them is how tenant
 * scopes go wrong. A read on a storefront is about the store that storefront
 * belongs to. A write from a panel or a console command is about the store the
 * row should end up in, which is not something the request can be asked.
 */
class StoreContext
{
    private static bool $spanningAllStores = false;

    /**
     * Run a callback with the store scope suspended.
     *
     * Some work is about a person rather than about a storefront, and narrowing
     * it to whichever host the request happened to arrive on makes it silently
     * incomplete. A subject-access export that returns one store's orders is a
     * wrong answer, not a partial one; an erasure that misses rows is a breach.
     *
     * Suspending it here rather than adding `withoutGlobalScope('store')` at
     * each query is deliberate: these paths read through relations
     * (`$user->customer`, `$user->wishlist()`) that no call-site opt-out
     * reaches, and every model added to the scope later would need remembering
     * again. That is the failure this whole trait exists to stop.
     */
    public static function acrossAllStores(callable $callback): mixed
    {
        $previous = self::$spanningAllStores;
        self::$spanningAllStores = true;

        try {
            return $callback();
        } finally {
            self::$spanningAllStores = $previous;
        }
    }

    /**
     * Narrow a query to the stores in scope, or leave it alone when none are.
     *
     * Leaving it alone is the right answer off a storefront and off a panel —
     * a console command, a queued job — where scoping to nothing would mean an
     * empty catalogue rather than a safe one.
     */
    public static function applyTo(Builder $query, string $column): void
    {
        if (self::$spanningAllStores) {
            return;
        }

        $storeIds = self::inScope();

        if ($storeIds !== []) {
            $query->whereIn($column, $storeIds);
        }
    }

    /**
     * The store a new row belongs to, or null when nothing can answer.
     *
     * A row left unstamped belongs to nobody rather than to whoever sorts
     * first, which is the `default(1)` mistake wave 2 is unpicking.
     */
    public static function forWrites(): ?int
    {
        if ($storeId = ChannelResolver::current()?->store_id) {
            return (int) $storeId;
        }

        // The panel user's own team answers first: with several stores on the
        // deployment it is the only thing that can, and it is right even when
        // the shortcut below would also have produced an answer.
        //
        // Falling through when their team owns none is not the same as
        // borrowing another team's store. The shortcut only answers when the
        // whole deployment has exactly one store — a single-tenant install,
        // where there is no other merchant to borrow from and the alternative
        // is a row invisible to the one storefront there is.
        $teamId = self::panelTeamId();

        if ($teamId !== null && ($storeId = self::theOnlyStoreOf($teamId)) !== null) {
            return $storeId;
        }

        return self::theOnlyStoreOf(null);
    }

    /**
     * The stores a read is scoped to.
     *
     * One for a resolved storefront. For a panel user, every store their team
     * owns — a merchant working in the panel is working across their whole
     * business, not one shopfront, and Filament gives them no store selector to
     * narrow it with.
     *
     * This is a second control rather than the control: panel *resources* are
     * already Team-scoped by Filament tenancy. What it adds is the paths that
     * scoping does not reach — relation managers, widgets, custom pages, and
     * any bare `Model::query()` written in a panel.
     *
     * @return list<int>
     */
    private static function inScope(): array
    {
        if ($storeId = ChannelResolver::current()?->store_id) {
            return [(int) $storeId];
        }

        $teamId = self::panelTeamId();

        return $teamId === null ? [] : self::storeIdsOf($teamId);
    }

    /**
     * The team a panel user is working in, or null off a panel.
     *
     * Read through Jetstream's `current_team_id` rather than Filament's tenant:
     * it is the same value — both panels switch it when the tenant changes —
     * and it can be asked off a panel, where the Filament facade has no panel
     * to answer for. It is also a column read, so it costs no query.
     *
     * Storefront shoppers never reach this branch even though they have teams
     * of their own: a resolved host answers first, and an unresolved one is a
     * 404 before any query runs.
     */
    private static function panelTeamId(): ?int
    {
        $teamId = auth()->user()?->current_team_id;

        return $teamId === null ? null : (int) $teamId;
    }

    /**
     * The store ids belonging to a team, or across the whole deployment.
     *
     * Read every time rather than remembered in a static. A cache here would be
     * two lines shorter and wrong in the one direction that matters: it goes
     * stale the moment a store is created, and staleness in a tenancy scope
     * shows another merchant's rows. The read is an indexed lookup on a table
     * with one row per storefront.
     *
     * @return list<int>
     */
    private static function storeIdsOf(?int $teamId): array
    {
        return Store::query()
            ->when($teamId !== null, fn (Builder $query) => $query->where('team_id', $teamId))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The single store a team owns, or the single store on the deployment when
     * `$teamId` is null. Null when there are none or several, because with
     * several there is no answer and inventing one is the original bug.
     *
     * Two rows are enough to tell "exactly one" from "more than one", which is
     * the whole question, so a write never reads a whole stores table.
     */
    private static function theOnlyStoreOf(?int $teamId): ?int
    {
        $storeIds = Store::query()
            ->when($teamId !== null, fn (Builder $query) => $query->where('team_id', $teamId))
            ->orderBy('id')
            ->limit(2)
            ->pluck('id');

        return $storeIds->count() === 1 ? (int) $storeIds->first() : null;
    }
}
