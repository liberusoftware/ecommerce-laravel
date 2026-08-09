<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        app(PermissionRegistrar::class)
            ->setPermissionClass(Permission::class)
            ->setRoleClass(Role::class);

        // Password::default() was already the rule everywhere (PasswordValidationRules,
        // SetUserPassword) but nothing ever configured it, so it resolved to Laravel's
        // stock min:8 with no complexity. These accounts hold order history and saved
        // payment methods; set the defaults once here and every caller inherits them —
        // registration, password reset, password update, and social account setup.
        Password::defaults(function () {
            $rule = Password::min(12)->mixedCase()->numbers()->symbols();

            // uncompromised() is a HaveIBeenPwned k-anonymity lookup: an outbound HTTPS
            // call to api.pwnedpasswords.com on the registration request. Worth it —
            // credential stuffing against reused breached passwords is the actual threat
            // to a storefront, and complexity rules alone happily accept "P@ssw0rd1234".
            // Production only, because the closure is what makes that affordable:
            // it defers to validation time, so the suite (APP_ENV=testing) and an
            // air-gapped/local deploy never touch the network. Laravel fails OPEN on a
            // request error, so an HIBP outage degrades to the complexity rules above
            // rather than blocking every signup.
            //
            // environment('production'), not isProduction(): ServiceProvider::$app is
            // typed as the Foundation\Application *contract*, which declares
            // environment() but not isProduction() — the latter only exists on the
            // concrete class. It resolves at runtime either way, but only this one is
            // sound against the declared type. isProduction() just delegates here.
            return $this->app->environment('production') ? $rule->uncompromised() : $rule;
        });
    }
}
