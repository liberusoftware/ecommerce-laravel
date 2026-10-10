<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->decimal('initial_value', 10, 2);
            $table->decimal('balance', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->text('note')->nullable();
            $table->string('template_suffix')->nullable();
            $table->string('last_characters', 4);
            $table->timestamps();

            $table->index(['code']);
            $table->index(['customer_id']);
            $table->index(['expires_at']);
            $table->index(['disabled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
    }
};