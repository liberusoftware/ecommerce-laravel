<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->unique();
            // A conversation is a customer talking to one merchant on one
            // storefront. Both keys were absent, which is why the Filament
            // resource opted out of tenancy and every team could read every
            // other team's conversations. Deliberately no default(1): an
            // unattributable row is left unstamped, which an operator can see
            // and fix — App\Traits\IsTenantModel records why the default was
            // itself the bug.
            // Nullable and unconstrained, both of them, matching
            // 2026_08_08_000002_add_store_id_to_team_scoped_tables: a foreign
            // key would force a default for a row that belongs to nobody, and
            // that default is the mistake being unpicked. `stores` is also
            // created two migrations from the end, long after this one — a
            // constraint here fails on MySQL with "Failed to open the
            // referenced table", which SQLite does not reproduce.
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('agent_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('status', ['queued', 'active', 'closed'])->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->integer('queue_position')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('agent_id');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
