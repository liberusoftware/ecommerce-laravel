<?php

namespace Tests\Feature;

use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\StockNotification;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Notifications\LowStockNotification;
use App\Notifications\ProductBackInStockNotification;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\BuildsVariants;
use Tests\TestCase;

/**
 * Variant stock moves through the same ledger as product stock, and reports
 * count it where it is held: a product with variants holds none of its own.
 */
class ProductVariantInventoryTest extends TestCase
{
    use BuildsVariants;
    use RefreshDatabase;

    private Product $boots;

    protected function setUp(): void
    {
        parent::setUp();

        $this->boots = $this->variableProduct(['Size' => ['40', '41', '42']], [
            $this->row(['Size' => '40'], 120, 0),
            $this->row(['Size' => '41'], 120, 2),
            $this->row(['Size' => '42'], 130, 9),
        ], ['name' => 'Trail Boot', 'inventory_count' => 50, 'low_stock_threshold' => 3]);
    }

    private function owner(): User
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id]);
        Store::query()->update(['team_id' => $team->id]);
        Product::query()->update(['team_id' => $team->id]);

        return $owner;
    }

    public function test_adjusting_a_variant_is_logged_against_it(): void
    {
        $variant = $this->variantOf($this->boots, '41');

        $this->assertTrue($variant->adjustInventory(5, 'delivery'));
        $this->assertFalse($variant->adjustInventory(-8, 'miscount'), 'Stock cannot go below zero.');

        $this->assertSame(7, $variant->fresh()->inventory_count);
        $this->assertSame(50, $this->boots->fresh()->inventory_count);
        $log = InventoryLog::sole();
        $this->assertSame([$this->boots->id, $variant->id, 5, 2, 7, 'delivery'], [$log->product_id, $log->product_variant_id, $log->quantity_change, $log->old_quantity, $log->new_quantity, $log->reason]);
    }

    public function test_the_adjust_endpoint_names_a_variant_for_a_product_with_variants(): void
    {
        $this->actingAs($this->owner());
        $variant = $this->variantOf($this->boots, '42');

        $this->postJson(route('inventory.adjust'), ['product_id' => $this->boots->id, 'quantity_change' => 1, 'reason' => 'count'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This product has variants: name the variant whose stock to adjust.');

        $this->postJson(route('inventory.adjust'), ['product_id' => $this->boots->id, 'variant_id' => $variant->id, 'quantity_change' => -4, 'reason' => 'count'])
            ->assertOk()
            ->assertJsonPath('variant.inventory_count', 5);

        $mug = Product::factory()->create(['inventory_count' => 1]);
        $this->postJson(route('inventory.adjust'), ['product_id' => $mug->id, 'variant_id' => $variant->id, 'quantity_change' => 1, 'reason' => 'count'])
            ->assertStatus(422);
        $this->assertSame(50, $this->boots->fresh()->inventory_count);
    }

    public function test_a_product_with_variants_reports_stock_through_its_variants(): void
    {
        $this->assertFalse($this->boots->tracksInventory());
        $this->assertFalse($this->boots->canSupply(1));
        $this->assertTrue($this->boots->isInStock());
        $this->assertTrue($this->boots->isLowStock());

        $this->boots->variants->each(fn ($v) => $v->update(['inventory_count' => 0]));
        $this->assertFalse($this->boots->fresh()->isInStock(), 'The product has 50 of its own, which no longer count.');
    }

    public function test_inventory_insights_count_each_variant_and_never_the_parent(): void
    {
        Product::factory()->create(['name' => 'Mug', 'price' => 10, 'inventory_count' => 4, 'low_stock_threshold' => 5]);

        $insights = app(AnalyticsService::class)->getInventoryInsights();

        $this->assertSame(['Trail Boot — 40', 'Trail Boot — 41', 'Mug'], array_column($insights['low_stock_products'], 'name'));
        $this->assertSame(3, $insights['low_stock_count']);
        $this->assertSame(1, $insights['out_of_stock_count']);
        // 2 × 120 + 9 × 130 + 4 × 10; the parent's own 50 are not stock.
        $this->assertEquals(1450.00, $insights['inventory_value']);
        $this->assertSame(['out_of_stock' => 1, 'low_stock' => 2, 'in_stock' => 1], $insights['stock_status']);
    }

    public function test_the_low_stock_check_alerts_on_a_variant(): void
    {
        Notification::fake();
        Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create()->assignRole('super_admin');

        $this->artisan('inventory:check-low-stock')->assertExitCode(0);

        Notification::assertSentTo($admin, LowStockNotification::class, fn (LowStockNotification $n) => $n->toArray($admin)['product_name'] === 'Trail Boot (41)'
            && $n->toArray($admin)['current_stock'] === 2);
        Notification::assertSentToTimes($admin, LowStockNotification::class, 2);
    }

    public function test_restocking_a_sold_out_variant_tells_people_waiting_for_the_product(): void
    {
        Notification::fake();
        StockNotification::create(['product_id' => $this->boots->id, 'email' => 'waiting@example.com', 'notification_type' => 'back_in_stock', 'notified' => false]);

        $this->variantOf($this->boots, '40')->adjustInventory(3, 'delivery');

        Notification::assertSentOnDemand(ProductBackInStockNotification::class);
        $this->assertTrue((bool) StockNotification::sole()->notified);
    }
}
