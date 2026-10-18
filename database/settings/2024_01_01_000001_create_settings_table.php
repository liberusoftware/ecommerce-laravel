<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.site_name', config('app.name', 'Liberu Ecommerce'));
        $this->migrator->add('general.site_email', 'info@example.com');
        $this->migrator->add('general.default_currency', strtoupper((string) config('ecommerce.default_currency', 'USD')));
        $this->migrator->add('general.default_locale', config('app.locale', 'en'));
        $this->migrator->add('general.allow_multiple_stores_per_team', (bool) config('ecommerce.installation.multiple_stores_per_team', false));
    }
};
