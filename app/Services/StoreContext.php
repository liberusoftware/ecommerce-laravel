<?php

namespace App\Services;

use App\Models\Store;

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
    /**
     * The store a read is scoped to, or null when there is nothing to scope by.
     *
     * Null off a resolved host — panels, console commands, queued jobs. Panels
     * are already Team-scoped by Filament tenancy, so leaving them unfiltered
     * here changes nothing about what a panel user can see; narrowing them to
     * the tenant's stores is a refinement for later, not a control.
     */
    public static function forReads(): ?int
    {
        return ChannelResolver::current()?->store_id;
    }

    /**
     * The store a new row belongs to.
     *
     * The resolved store when there is one. Otherwise the only store, if there
     * is exactly one — that is not a guess, it is the whole truth on a
     * single-store deployment, and it is what keeps a product created in a
     * panel visible on the storefront that sells it.
     *
     * With several stores and no resolved host there is no answer, and the row
     * is left unstamped rather than attributed to whichever store sorts first.
     */
    public static function forWrites(): ?int
    {
        return self::forReads() ?? self::theOnlyStoreId();
    }

    private static function theOnlyStoreId(): ?int
    {
        $stores = Store::query()->orderBy('id')->limit(2)->pluck('id');

        return $stores->count() === 1 ? (int) $stores->first() : null;
    }
}
