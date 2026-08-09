<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moderation moves with the reviews it moderates — [ADR 0008](../../docs/adr/0008-reviews-and-ratings-merge.md).
 *
 * Two review stacks existed: `reviews`, keyed to a `User` and carrying an
 * `approved` flag, and `product_reviews`, keyed to a `Customer`, store-scoped,
 * and carrying verified-purchase and helpfulness votes. The second wins the
 * merge, and it had no moderation column at all — because moderation lived
 * entirely in the stack being retired.
 *
 * Retiring that stack without porting this column is the failure the ADR names:
 * every review would go straight to the public listing, and a content-safety
 * regression would ship disguised as a schema cleanup. Nothing in the diff
 * would say so — the tests that pass are the tests that no longer exist.
 *
 * Default `false`, matching what the controller has always written: a review is
 * published by a decision, never by arriving.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_reviews') || Schema::hasColumn('product_reviews', 'approved')) {
            return;
        }

        Schema::table('product_reviews', function (Blueprint $table) {
            $table->boolean('approved')->default(false)->after('comments');

            // The public listing filters on exactly this pair, and it is the
            // one review query a shopper waits on.
            $table->index(['product_id', 'approved']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_reviews') || ! Schema::hasColumn('product_reviews', 'approved')) {
            return;
        }

        Schema::table('product_reviews', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'approved']);
            $table->dropColumn('approved');
        });
    }
};
