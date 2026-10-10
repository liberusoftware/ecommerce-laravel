<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add fields to abandoned_carts if not exists
        if (Schema::hasTable('abandoned_carts')) {
            Schema::table('abandoned_carts', function (Blueprint $table) {
                if (!Schema::hasColumn('abandoned_carts', 'recovery_email_sent_at')) {
                    $table->timestamp('recovery_email_sent_at')->nullable()->after('abandoned_at');
                }
                if (!Schema::hasColumn('abandoned_carts', 'recovery_sms_sent_at')) {
                    $table->timestamp('recovery_sms_sent_at')->nullable()->after('recovery_email_sent_at');
                }
                if (!Schema::hasColumn('abandoned_carts', 'recovered_at')) {
                    $table->timestamp('recovered_at')->nullable()->after('recovery_sms_sent_at');
                }
                if (!Schema::hasColumn('abandoned_carts', 'recovery_token')) {
                    $table->string('recovery_token')->nullable()->unique()->after('recovered_at');
                }
                if (!Schema::hasColumn('abandoned_carts', 'recovery_discount_code')) {
                    $table->string('recovery_discount_code')->nullable()->after('recovery_token');
                }
            });
        }

        // Recovery campaign templates
        Schema::create('cart_recovery_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('trigger_type', ['email', 'sms', 'both'])->default('email');
            $table->integer('delay_minutes')->default(60); // Time after abandonment
            $table->string('email_subject')->nullable();
            $table->text('email_body')->nullable();
            $table->text('sms_body')->nullable();
            $table->boolean('include_discount')->default(false);
            $table->enum('discount_type', ['percentage', 'fixed'])->nullable();
            $table->decimal('discount_value', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->json('conditions')->nullable(); // Cart value, items, etc.
            $table->timestamps();
        });

        // Track recovery campaign sends
        Schema::create('cart_recovery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('abandoned_cart_id')->constrained()->onDelete('cascade');
            $table->foreignId('campaign_id')->constrained('cart_recovery_campaigns')->onDelete('cascade');
            $table->enum('channel', ['email', 'sms']);
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamps();
            
            $table->index('sent_at');
            $table->index(['abandoned_cart_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_recovery_attempts');
        Schema::dropIfExists('cart_recovery_campaigns');
        
        if (Schema::hasTable('abandoned_carts')) {
            Schema::table('abandoned_carts', function (Blueprint $table) {
                $table->dropColumn([
                    'recovery_email_sent_at',
                    'recovery_sms_sent_at',
                    'recovered_at',
                    'recovery_token',
                    'recovery_discount_code',
                ]);
            });
        }
    }
};
