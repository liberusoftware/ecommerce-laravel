<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring the schema in line with Laravel Cashier v16 now that the package is
 * installed. The hand-rolled subscriptions table used Cashier's OLD column names
 * (name/stripe_plan); Cashier v16 expects type/stripe_price plus a
 * subscription_items table and Stripe columns on the billable (users).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'stripe_id')) {
                $table->string('stripe_id')->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'pm_type')) {
                $table->string('pm_type')->nullable();
            }
            if (! Schema::hasColumn('users', 'pm_last_four')) {
                $table->string('pm_last_four', 4)->nullable();
            }
            if (! Schema::hasColumn('users', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable();
            }
        });

        if (Schema::hasColumn('subscriptions', 'name') && ! Schema::hasColumn('subscriptions', 'type')) {
            Schema::table('subscriptions', fn (Blueprint $table) => $table->renameColumn('name', 'type'));
        }
        if (Schema::hasColumn('subscriptions', 'stripe_plan') && ! Schema::hasColumn('subscriptions', 'stripe_price')) {
            Schema::table('subscriptions', fn (Blueprint $table) => $table->renameColumn('stripe_plan', 'stripe_price'));
        }

        if (! Schema::hasTable('subscription_items')) {
            Schema::create('subscription_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('subscription_id');
                $table->string('stripe_id')->unique();
                $table->string('stripe_product');
                $table->string('stripe_price');
                $table->integer('quantity')->nullable();
                $table->timestamps();

                $table->index(['subscription_id', 'stripe_price']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_items');

        if (Schema::hasColumn('subscriptions', 'type') && ! Schema::hasColumn('subscriptions', 'name')) {
            Schema::table('subscriptions', fn (Blueprint $table) => $table->renameColumn('type', 'name'));
        }
        if (Schema::hasColumn('subscriptions', 'stripe_price') && ! Schema::hasColumn('subscriptions', 'stripe_plan')) {
            Schema::table('subscriptions', fn (Blueprint $table) => $table->renameColumn('stripe_price', 'stripe_plan'));
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
