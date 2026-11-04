<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_requests', function (Blueprint $table) {
            $table->id();
            // Unconstrained, as on chat_conversations: an unattributable row stays unstamped.
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name', 100);
            $table->string('email', 254);
            $table->string('subject', 150)->nullable();
            $table->text('message');
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_requests');
    }
};
