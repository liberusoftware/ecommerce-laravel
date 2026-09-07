<?php

/*
|--------------------------------------------------------------------------
| Modules
|--------------------------------------------------------------------------
|
| Published from liberusoftware/module-manager. See ADR 0011 for what this
| replaced and what was given up.
|
| The shape to know: enablement is deployment configuration, resolved once in
| the provider's register(), not mutable state in a database table. So the set
| of registered providers is a fixed function of the deployment — which is what
| makes a boot reproducible, and is the whole reason the previous DB-backed
| module system was dropped rather than carried across.
|
| Nothing is enabled unless MODULES_ENABLED names it. That default is
| deliberate and it inverts the old behaviour, which treated an unknown module
| as enabled — including when the lookup threw. A module runs because somebody
| decided it should, never because nobody decided otherwise.
|
*/

return [
    'paths' => [base_path('modules')],

    // Installed packages are disabled by default. The host application owns its explicit selection.
    'enabled' => array_values(array_filter(explode(',', (string) env('MODULES_ENABLED', '')))),
    'disabled' => array_values(array_filter(explode(',', (string) env('MODULES_DISABLED', '')))),

    'cache' => (bool) env('MODULES_CACHE', false),
    'cache_path' => base_path('bootstrap/cache/liberu-modules.php.cache'),
];
