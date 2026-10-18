<?php

namespace App\Support;

/** What a team member may do inside their own team's stores. Roles are defined in JetstreamServiceProvider. */
final class TeamPermission
{
    public const ROLE_ADMIN = 'admin';

    public const ROLE_CATALOGUE = 'catalogue';

    public const ROLE_ORDERS = 'orders';

    public const ROLE_VIEWER = 'viewer';

    public const CATALOGUE_VIEW = 'catalogue:view';

    public const CATALOGUE_MANAGE = 'catalogue:manage';

    public const ORDERS_VIEW = 'orders:view';

    public const ORDERS_MANAGE = 'orders:manage';

    public const CUSTOMERS_VIEW = 'customers:view';

    public const CUSTOMERS_MANAGE = 'customers:manage';

    /** Store settings, shipping, payment acceptance, integrations. */
    public const STORE_MANAGE = 'store:manage';

    /** Inviting, removing and re-roling members. */
    public const TEAM_MANAGE = 'team:manage';

    /** The view permission a manage permission implies. */
    public static function viewFor(string $manage): string
    {
        return str_replace(':manage', ':view', $manage);
    }
}
