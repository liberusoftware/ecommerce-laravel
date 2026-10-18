<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shipping methods were one list shared by every store on the deployment.
 * Existing methods are given to each store so no checkout loses its options.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->unsignedBigInteger('store_id')->nullable()->after('id')->index();
        });

        $storeIds = DB::table('stores')->orderBy('id')->pluck('id');

        if ($storeIds->isEmpty()) {
            return;
        }

        foreach (DB::table('shipping_methods')->whereNull('store_id')->get() as $method) {
            DB::table('shipping_methods')->where('id', $method->id)->update(['store_id' => $storeIds->first()]);

            foreach ($storeIds->slice(1) as $storeId) {
                $copy = (array) $method;
                unset($copy['id']);
                DB::table('shipping_methods')->insert(['store_id' => $storeId] + $copy);
            }
        }
    }

    public function down(): void
    {
        Schema::table('shipping_methods', function (Blueprint $table) {
            $table->dropColumn('store_id');
        });
    }
};
