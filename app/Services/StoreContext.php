<?php

namespace App\Services;

use App\Models\Store;
use Filament\Facades\Filament;
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

    private static ?int $writingTo = null;

    /**
     * The platform panel (/admin), for a user it admits: reads span every store,
     * and a write lands only in the store the caller names with writingTo().
     * Guessing one from the operator's own team would file platform edits under
     * whichever merchant they happen to belong to. What they may see or change is
     * each resource's policy, against their platform permissions.
     */
    public static function isPlatform(): bool
    {
        $panel = Filament::getCurrentPanel();
        $user = auth()->user();

        return $panel?->getId() === 'admin' && $user !== null && $user->canAccessPanel($panel);
    }

    /**
     * Stamp rows created inside the callback with a store the caller chose — a
     * panel form where the team owns several and the merchant picked one. The
     * caller is responsible for having checked the store is theirs.
     */
    public static function writingTo(int $storeId, callable $callback): mixed
    {
        $previous = self::$writingTo;
        self::$writingTo = $storeId;

        try {
            return $callback();
        } finally {
            self::$writingTo = $previous;
        }
    }

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
     * Narrow a query to the stores in scope, or leave it alone when there is
     * nobody to scope by.
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

        // An empty list matches nothing: a panel user with no store to read.
        if ($storeIds !== null) {
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
        if (self::$writingTo !== null) {
            return self::$writingTo;
        }

        if (self::isPlatform()) {
            return null;
        }

        if ($storeId = ChannelResolver::current()?->store_id) {
            return (int) $storeId;
        }

        // The panel user's own team answers first: with several stores on the
        // deployment it is the only thing that can, and it is right even when
        // the shortcut below would also have produced an answer.
        //
        // A team that owns none falls back only to a store no team owns: the
        // single store of a single-tenant install. One owned by another team
        // is that merchant's, not a shared default.
        $teamId = self::panelTeamId();

        if ($teamId !== null) {
            return self::theOnlyStoreOf($teamId) ?? self::theOnlyUnownedStore();
        }

        return self::theOnlyStoreOf(null);
    }

    /**
     * The team a new row belongs to, or null when nothing can answer.
     *
     * Derived from the store rather than asked separately, because a store
     * belongs to exactly one team and two independent answers can disagree —
     * which on a tenant key means a row a merchant can see in the panel and not
     * on their storefront, or the reverse.
     *
     * Off a storefront and off a panel this is null, and the row is left
     * unstamped. That is the whole correction: a tenant key with a `default(1)`
     * turns "nobody said" into "team 1", silently, and every row created by the
     * API, a controller, a seeder or a factory took that default.
     */
    public static function teamForWrites(): ?int
    {
        $storeId = self::forWrites();

        if ($storeId === null && self::isPlatform()) {
            return null;
        }

        if ($storeId !== null) {
            $teamId = Store::query()->whereKey($storeId)->value('team_id');

            if ($teamId !== null) {
                return (int) $teamId;
            }
        }

        // A panel user whose team owns no store yet: the panel is the only
        // thing that can answer, and it is right — the row is theirs.
        return self::panelTeamId();
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
     * A panel user whose team owns no store reads the store `forWrites()` would
     * stamp — the only store on the deployment if no team owns it — and
     * otherwise none.
     * Null means nobody to scope by: off a storefront and off a panel.
     *
     * @return list<int>|null
     */
    private static function inScope(): ?array
    {
        if ($storeId = ChannelResolver::current()?->store_id) {
            return [(int) $storeId];
        }

        if (self::isPlatform()) {
            return null;
        }

        $teamId = self::panelTeamId();

        if ($teamId === null) {
            return null;
        }

        $storeIds = self::storeIdsOf($teamId);

        if ($storeIds !== []) {
            return $storeIds;
        }

        $onlyStore = self::theOnlyUnownedStore();

        return $onlyStore === null ? [] : [$onlyStore];
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

    /**
     * The deployment's single store when no team owns it, otherwise null.
     */
    private static function theOnlyUnownedStore(): ?int
    {
        $stores = Store::query()->orderBy('id')->limit(2)->get(['id', 'team_id']);

        return $stores->count() === 1 && $stores->first()->team_id === null
            ? (int) $stores->first()->id
            : null;
    }
}
