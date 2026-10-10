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
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('download_link')->nullable()->after('price');
            $table->timestamp('download_expires_at')->nullable()->after('download_link');
            $table->integer('download_count')->default(0)->after('download_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['download_link', 'download_expires_at', 'download_count']);
        });
    }
};
