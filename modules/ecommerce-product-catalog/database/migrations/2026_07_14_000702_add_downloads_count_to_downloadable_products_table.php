<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DownloadableProduct's $fillable/$casts include downloads_count, but the
     * table never had the column -> any create with it fatals.
     */
    public function up(): void
    {
        Schema::table('downloadable_products', function (Blueprint $table) {
            if (! Schema::hasColumn('downloadable_products', 'downloads_count')) {
                $table->integer('downloads_count')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('downloadable_products', function (Blueprint $table) {
            $table->dropColumn('downloads_count');
        });
    }
};
