<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record an intra-EU B2B sale: the buyer's VAT number and whether the order was
 * zero-rated under the reverse-charge rule (the buyer accounts for the VAT). Needed so
 * the order shows why no VAT was charged, and so the OSS report can exclude it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'vat_number')) {
                $table->string('vat_number')->nullable()->after('billing_country');
            }
            if (! Schema::hasColumn('orders', 'reverse_charge')) {
                $table->boolean('reverse_charge')->default(false)->after('vat_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            foreach (['vat_number', 'reverse_charge'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
