<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A live carrier rate the server fetched and persisted for one browser session. At
 * checkout the buyer selects a quote by id and the server bills its STORED amount —
 * an API-quoted price can't be recomputed from a row, and a client-posted price must
 * never be trusted, so the quote is the only source of truth for what to charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipping_quotes')) {
            return;
        }

        Schema::create('shipping_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->index();       // scopes a quote to its browser session
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('carrier');
            $table->string('service');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('delivery_days')->nullable();
            $table->string('rate_id')->nullable();        // carrier's own rate id (e.g. EasyPost)
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_quotes');
    }
};
