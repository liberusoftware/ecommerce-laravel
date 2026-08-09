<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes 2024_10_10_100000_add_team_to_resources: three tables backing App-panel
 * Filament resources (invoices, articles, product_rating) were left without a
 * team_id, so the Team-tenant-scoped panel could not scope them. Adds the same
 * nullable team_id (default 1, cascade) those siblings already carry.
 */
return new class extends Migration
{
    protected $tables = ['invoices', 'articles', 'product_rating'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'team_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->foreignId('team_id')->nullable()->constrained()->onDelete('cascade');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'team_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->dropForeign(['team_id']);
                    $table->dropColumn('team_id');
                });
            }
        }
    }
};
