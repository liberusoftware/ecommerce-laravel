<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every environment one resolvable channel, before anything depends on
 * resolution succeeding.
 *
 * The order matters. Wave 1.5 ends with an unresolved host being a 404, and no
 * default-merchant fallback — but a control that refuses unconfigured hosts,
 * shipped into environments where no host is configured, takes the storefront
 * down everywhere at once. So the data lands first, in its own migration, and
 * the control follows. Same discipline as wave 2's backfill-before-scope.
 *
 * This is not the fallback the wave rules out. A fallback answers for hosts
 * nobody configured; this configures the host the deployment already answers
 * on, which on a single-store deployment is the whole truth.
 *
 * Plain DB rather than Eloquent: a model that gets renamed or gains a global
 * scope later must not change what this migration did.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent, and a no-op on any deployment that has already been set up
        // by hand. Claiming hostnames out from under an existing channel is the
        // one thing this must never do.
        if (DB::table('channels')->exists() || DB::table('stores')->exists()) {
            return;
        }

        $now = now();

        $storeId = DB::table('stores')->insertGetId([
            // The first team if there is one — a single-store deployment has
            // exactly one merchant, which is the assumption this whole migration
            // rests on. Null otherwise, and wave 2 fills it in.
            'team_id' => DB::table('teams')->orderBy('id')->value('id'),
            'name' => config('app.name', 'Store'),
            'slug' => 'default',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $channelId = DB::table('channels')->insertGetId([
            'store_id' => $storeId,
            'name' => 'Web',
            'theme' => 'theme-ecommerce',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $primary = $this->host(config('app.url'));

        // The apex the deployment is configured for, plus the two hostnames
        // local development and the test suite arrive on. Only the first is
        // canonical.
        $hosts = array_values(array_unique(array_filter([$primary, 'localhost', '127.0.0.1'])));

        foreach ($hosts as $host) {
            DB::table('channel_domains')->insert([
                'channel_id' => $channelId,
                'host' => $host,
                'is_primary' => $host === $primary,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately empty. Rolling back the schema drops these rows with it;
        // rolling back only this migration would leave a deployment with no
        // resolvable host, which is worse than leaving a store behind.
    }

    private function host(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);

        return $host ? strtolower($host) : null;
    }
};
