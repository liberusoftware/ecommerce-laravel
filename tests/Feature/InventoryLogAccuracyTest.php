<?php

namespace Tests\Feature;

use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Services\CheckoutService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The stock audit row must describe our own change, even when another checkout
 * moves the same product's stock while ours runs.
 */
class InventoryLogAccuracyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_log_records_the_stock_our_reservation_left(): void
    {
        $product = Product::factory()->create(['inventory_count' => 10]);
        $order = Order::forceCreate(['total_amount' => 0, 'status' => 'pending', 'payment_status' => 'pending', 'order_date' => now()]);

        // Simulate a competing checkout taking 5 the first time our code reads
        // the stock, and note whether that happened before our own update.
        $updated = false;
        $competitorRanBeforeOurUpdate = null;
        DB::listen(function (QueryExecuted $query) use ($product, &$updated, &$competitorRanBeforeOurUpdate) {
            $sql = str_replace('`', '"', $query->sql);
            if (str_starts_with($sql, 'update "products"')) {
                $updated = true;
            } elseif ($competitorRanBeforeOurUpdate === null && str_contains($sql, '"inventory_count" from "products"')) {
                $competitorRanBeforeOurUpdate = ! $updated;
                DB::table('products')->where('id', $product->id)->decrement('inventory_count', 5);
            }
        });

        DB::transaction(fn () => app(CheckoutService::class)->reserveStock($order, [
            ['product_id' => $product->id, 'quantity' => 2, 'price' => 1.0],
        ]));

        $final = (int) DB::table('products')->where('id', $product->id)->value('inventory_count');
        $afterOurUpdate = $competitorRanBeforeOurUpdate ? $final : $final + 5;
        $log = InventoryLog::firstOrFail();

        $this->assertSame(3, $final);
        $this->assertSame($afterOurUpdate, (int) $log->new_quantity);
        $this->assertSame($afterOurUpdate + 2, (int) $log->old_quantity);
    }
}
