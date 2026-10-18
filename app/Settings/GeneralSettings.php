<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Platform-wide settings, owned by the operator. What a merchant controls lives on their Store.
 */
class GeneralSettings extends Settings
{
    public string $site_name;

    public string $site_email;

    /** Given to a store when it is created. */
    public string $default_currency;

    public string $default_locale;

    public bool $allow_multiple_stores_per_team;

    public static function group(): string
    {
        return 'general';
    }
}
