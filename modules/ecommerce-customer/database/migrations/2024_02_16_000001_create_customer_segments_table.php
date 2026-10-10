<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_segments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('conditions'); // Store segmentation rules
            $table->enum('match_type', ['all', 'any'])->default('all'); // Match all or any conditions
            $table->boolean('is_active')->default(true);
            $table->integer('customer_count')->default(0); // Cached count
            $table->timestamp('last_calculated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_segment_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('segment_id')->constrained('customer_segments')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamp('added_at')->useCurrent();
            $table->unique(['segment_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_segment_members');
        Schema::dropIfExists('customer_segments');
    }
};
