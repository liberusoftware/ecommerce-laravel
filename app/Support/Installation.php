<?php

namespace App\Support;

use App\Models\Store;
use App\Models\Team;
use InvalidArgumentException;

/**
 * The installation's operating model, from config/ecommerce.php.
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
        return (bool) config('ecommerce.installation.multiple_stores_per_team', false);
    }

    /** A single-store installation has one store in total; otherwise one per team unless several are allowed. */
    public static function canCreateStoreFor(Team $team): bool
    {
        if (self::mode() === self::SINGLE) {
            return ! Store::query()->exists();
        }

        return self::allowsMultipleStoresPerTeam() || ! $team->stores()->exists();
    }

}
