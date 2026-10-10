<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_group_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_group_id')->constrained()->onDelete('cascade');
            $table->timestamp('joined_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'customer_group_id']);
            $table->index(['customer_id']);
            $table->index(['customer_group_id']);
            $table->index(['expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_group_memberships');
    }
};