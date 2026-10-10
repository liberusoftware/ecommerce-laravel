<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Customer is the same identity as a User: link them 1:1 via customers.user_id.
 * A user's customer record starts minimal (just name + email), so the profile
 * fields (phone/address/…) that guest checkout fills become nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('customers', 'user_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->unique()->after('id')->constrained()->nullOnDelete();
            });
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->integer('phone_number')->nullable()->change();
            $table->string('address')->nullable()->change();
            $table->string('city')->nullable()->change();
            $table->string('state')->nullable()->change();
            $table->string('postal_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('customers', 'user_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('user_id');
            });
        }
    }
};
