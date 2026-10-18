<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a merchant configures about their own store: identity, address,
 * regional formatting and checkout. Deployment-wide values stay in config.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
            $table->string('tagline')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->char('currency_code', 3)->default('USD');
            $table->string('locale', 10)->default('en');
            $table->boolean('display_prices_with_tax')->default(false);
            // Null means every gateway the deployment has credentials for.
            $table->json('payment_gateways')->nullable();
            $table->json('social_links')->nullable();
            $table->string('footer_text', 500)->nullable();
        });

        // Carry the deployment's existing env-level values onto the stores that predate this.
        DB::table('stores')->update([
            'currency_code' => strtoupper((string) config('ecommerce.default_currency', 'USD')),
            'country_code' => config('ecommerce.store_country') ? strtoupper((string) config('ecommerce.store_country')) : null,
            'region' => config('ecommerce.store_state'),
            'display_prices_with_tax' => (bool) config('ecommerce.display_prices_with_tax', false),
        ]);
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'logo_path', 'tagline', 'email', 'phone', 'address_line1', 'address_line2', 'city', 'region',
                'postal_code', 'country_code', 'currency_code', 'locale', 'display_prices_with_tax',
                'payment_gateways', 'social_links', 'footer_text',
            ]);
        });
    }
};
