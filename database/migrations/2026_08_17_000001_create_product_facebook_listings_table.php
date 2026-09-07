<?php

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_facebook_listings', function (Blueprint $table) {
            $table->id();
            // No team_id: the Product's tenancy owns this row, the way
            // ProductVariant's does.
            $table->foreignIdFor(Product::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(ProductVariant::class)->nullable()->constrained()->cascadeOnDelete();
            $table->string('retailer_id')->unique();       // app-side key sent to Meta as data.id
            $table->string('catalog_item_id')->nullable(); // Meta-generated, filled on reconcile
            $table->string('status')->default('pending');  // pending|active|error|out_of_stock|deleted
            $table->json('errors')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_facebook_listings');
    }
};
