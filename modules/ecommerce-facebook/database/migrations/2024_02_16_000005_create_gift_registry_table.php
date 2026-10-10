<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_registries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name'); // e.g., "John & Jane's Wedding"
            $table->string('slug')->unique();
            $table->enum('type', ['wedding', 'baby', 'birthday', 'anniversary', 'holiday', 'other'])->default('other');
            $table->date('event_date')->nullable();
            $table->text('message')->nullable();
            $table->string('location')->nullable();
            $table->enum('privacy', ['public', 'private', 'link_only'])->default('public');
            $table->string('access_code')->nullable(); // For private registries
            $table->boolean('is_active')->default(true);
            $table->string('shipping_name')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_state')->nullable();
            $table->string('shipping_postal_code')->nullable();
            $table->string('shipping_country')->nullable();
            $table->timestamps();
            
            $table->index('slug');
            $table->index(['type', 'is_active']);
        });

        Schema::create('gift_registry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registry_id')->constrained('gift_registries')->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_variant_id')->nullable()->constrained()->onDelete('set null');
            $table->integer('quantity_requested')->default(1);
            $table->integer('quantity_purchased')->default(0);
            $table->integer('priority')->default(0); // 1=must have, 2=nice to have, etc.
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index('registry_id');
        });

        Schema::create('gift_registry_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registry_item_id')->constrained('gift_registry_items')->onDelete('cascade');
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->string('purchaser_name')->nullable();
            $table->string('purchaser_email')->nullable();
            $table->boolean('anonymous')->default(false);
            $table->timestamp('purchased_at')->useCurrent();
            
            $table->index('registry_item_id');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_registry_purchases');
        Schema::dropIfExists('gift_registry_items');
        Schema::dropIfExists('gift_registries');
    }
};
