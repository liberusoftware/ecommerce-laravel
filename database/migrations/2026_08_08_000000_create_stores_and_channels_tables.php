<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The schema wave 1.5 is built on: host → Channel → Store → Team.
 *
 * A `Team` may own several `Store`s, and a shopper on store A's domain must see
 * store A's catalogue rather than everything their merchant sells across every
 * store. That is why commerce scopes on `store_id` and not `team_id` — `team_id`
 * is the wrong grain and would under-scope.
 *
 * `channel_domains` is a table rather than a `domain` column on `channels`
 * because a storefront answers on several hostnames from day one: the apex,
 * `www`, a custom merchant domain, a platform subdomain. One column pushes
 * apex/`www` handling into web-server config the application cannot see, and
 * then the canonical the app generates and the host the request arrived on can
 * disagree. One row per hostname, one flagged primary for canonicals.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stores')) {
            Schema::create('stores', function (Blueprint $table) {
                $table->id();
                // Nullable for the same reason every other tenant column here is:
                // a store that predates ownership belongs to nobody rather than
                // to whoever happens to be first. Wave 2 fills these in.
                $table->foreignId('team_id')->nullable()->index();
                $table->string('name');
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('channels')) {
            Schema::create('channels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                // One storefront, theme selected per resolved channel. Per-merchant
                // themes are later children with `parent: ecommerce`.
                $table->string('theme')->default('theme-ecommerce');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('channel_domains')) {
            Schema::create('channel_domains', function (Blueprint $table) {
                $table->id();
                $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
                // Hostname only — no scheme, no port. Unique across every channel:
                // a hostname resolving to two storefronts is the ambiguity this
                // whole table exists to prevent.
                $table->string('host')->unique();
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_domains');
        Schema::dropIfExists('channels');
        Schema::dropIfExists('stores');
    }
};
