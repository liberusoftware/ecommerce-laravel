<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seeded super_admin password
    |--------------------------------------------------------------------------
    |
    | The password `UserSeeder` gives the `admin@example.com` super_admin.
    |
    | Outside `local`, seeding creates that account only when this is set. The
    | alternative is a generated password, which has to be printed to be usable
    | — and `install.yml` runs `db:seed --force`, so printing writes a working
    | super_admin credential into a shared log (#940). Leaving it unset there
    | means no account rather than an account nobody can log into.
    |
    | On `local` it can stay unset: the seeder generates one and prints it,
    | which is what a developer bootstrapping their own machine wants.
    |
    */

    'admin_password' => env('SEED_ADMIN_PASSWORD'),

];
