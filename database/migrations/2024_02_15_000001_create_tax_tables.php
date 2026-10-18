<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tax Classes (e.g., Standard, Reduced, Zero-rated)
        Schema::create('tax_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Tax Rates (actual tax percentages)
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_class_id')->constrained()->cascadeOnDelete();
            $table->string('country', 2);
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('zip_code')->nullable();
            $table->decimal('rate', 8, 4); // e.g., 8.5000 for 8.5%
            $table->string('name');
            $table->integer('priority')->default(1);
            $table->boolean('compound')->default(false); // compound tax
            $table->boolean('shipping')->default(false); // apply to shipping
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['country', 'state', 'city']);
        });

        // Add tax_class_id to products table
        if (! Schema::hasColumn('products', 'tax_class_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('tax_class_id')->nullable()->constrained()->nullOnDelete();
                $table->boolean('tax_status')->default(true); // taxable or not
            });
        }

        // The order's total tax is orders.tax_amount; this is its breakdown.
        if (! Schema::hasColumn('orders', 'tax_lines')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->json('tax_lines')->nullable();
            });
        }

    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('tax_lines');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['tax_class_id']);
            $table->dropColumn(['tax_class_id', 'tax_status']);
        });

        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_classes');
    }
};
