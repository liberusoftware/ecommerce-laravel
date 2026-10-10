<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fix the orders table so guest / gift checkout can create an order:
     *  - recipient_name / recipient_email / gift_message are written by the
     *    Order model + CheckoutController but were never migrated (every
     *    checkout fataled at order creation).
     *  - customer_id was NOT NULL (foreignId()->constrained()), but guest
     *    checkout creates email-only orders with no customer, so it must be
     *    nullable or guest orders violate the constraint.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'recipient_name')) {
                $table->string('recipient_name')->nullable();
            }
            if (! Schema::hasColumn('orders', 'recipient_email')) {
                $table->string('recipient_email')->nullable();
            }
            if (! Schema::hasColumn('orders', 'gift_message')) {
                $table->text('gift_message')->nullable();
            }
        });

        // Legacy NOT-NULL columns from the original create_orders_table that the
        // checkout flow never populates (it uses customer_email + status instead).
        // Left required, they block every order insert.
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->change();
            $table->string('order_date')->nullable()->change();
            $table->string('payment_status')->nullable()->change();
            $table->string('shipping_status')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['recipient_name', 'recipient_email', 'gift_message']);
        });
    }
};
