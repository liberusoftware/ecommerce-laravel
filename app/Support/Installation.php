<?php

namespace App\Support;

use App\Models\Store;
use App\Models\Team;
use App\Settings\GeneralSettings;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The installation's operating model. The mode is deployment config; whether a
 * team may own several stores is a platform setting the operator changes in the UI.
 */
final class Installation
{
    public const SINGLE = 'single';

    public const SAAS = 'saas';

    public const MARKETPLACE = 'marketplace';

    public static function mode(): string
    {
        $mode = config('ecommerce.installation.mode', self::SINGLE);

        if (! in_array($mode, [self::SINGLE, self::SAAS, self::MARKETPLACE], true)) {
            throw new InvalidArgumentException("Unknown installation mode [{$mode}]; expected single, saas or marketplace.");
        }

        return $mode;
    }

    public static function allowsMultipleStoresPerTeam(): bool
    {
        try {
            return app(GeneralSettings::class)->allow_multiple_stores_per_team;
        } catch (Throwable) {
            // Settings not migrated yet: the env value is what the setting is seeded from.
            return (bool) config('ecommerce.installation.multiple_stores_per_team', false);
        }
    }

    /**
     * A single-store installation has one merchant: one store, or several for that
     * merchant alone when the platform allows it. Otherwise one per team unless
     * several are allowed.
     */
    public static function canCreateStoreFor(Team $team): bool
    {
        if (self::mode() === self::SINGLE) {
            if (! Store::query()->exists()) {
                return true;
            }

            return self::allowsMultipleStoresPerTeam()
                && ! Store::query()->whereNotNull('team_id')->where('team_id', '!=', $team->id)->exists();
        }

        return self::allowsMultipleStoresPerTeam() || ! $team->stores()->exists();
    }

    /**
     * Give a new team its store, except on a single-store installation. The store
     * has no channel, so it is not public until a domain is mapped to it.
     */
    public static function provisionStoreFor(Team $team): ?Store
    {
        if (self::mode() === self::SINGLE || $team->stores()->exists()) {
            return null;
        }

        return $team->stores()->create([
            'name' => $team->name,
            'slug' => Str::slug($team->name).'-'.$team->id,
        ]);
    }
}
