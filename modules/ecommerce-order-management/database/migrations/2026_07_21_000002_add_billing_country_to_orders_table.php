<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the buyer country that VAT was charged against. shipping_address is a
 * freeform string, so without this the member state of consumption is lost after
 * checkout — and the OSS/MOSS return needs VAT attributed per member state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'billing_country')) {
                $table->string('billing_country', 2)->nullable()->index()->after('shipping_address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'billing_country')) {
                $table->dropColumn('billing_country');
            }
        });
    }
};
