<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local record of a PayPal subscription so the app can own it (link to a user) and
 * keep its lifecycle status in sync via webhooks — PayPal, not the app, decides when
 * a subscription activates/cancels/expires, and those transitions arrive out of band.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('paypal_subscriptions')) {
            return;
        }

        Schema::create('paypal_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('paypal_subscription_id')->unique();
            $table->string('plan_id');
            $table->string('status')->default('APPROVAL_PENDING');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_subscriptions');
    }
};
