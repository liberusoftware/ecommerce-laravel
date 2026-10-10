<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->string('session_id');
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            $table->text('page_url')->nullable();
            $table->text('referrer_url')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('device_type')->nullable();
            $table->string('browser')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->json('event_data')->nullable();
            $table->decimal('revenue', 10, 2)->nullable();
            $table->integer('quantity')->nullable();
            $table->string('search_term')->nullable();
            $table->timestamps();

            $table->index(['event_type']);
            $table->index(['customer_id']);
            $table->index(['session_id']);
            $table->index(['product_id']);
            $table->index(['order_id']);
            $table->index(['created_at']);
            $table->index(['utm_source']);
            $table->index(['utm_campaign']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};