<?php

use App\Models\Team;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_connections', function (Blueprint $table) {
            $table->id();
            // Unique: one Meta Catalog per merchant, never a shared one.
            $table->foreignIdFor(Team::class)->unique()->constrained()->cascadeOnDelete();
            $table->text('access_token');            // encrypted by the model cast
            $table->string('catalog_id');
            $table->string('business_id')->nullable();
            $table->string('graph_version')->default('v21.0');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_connections');
    }
};
