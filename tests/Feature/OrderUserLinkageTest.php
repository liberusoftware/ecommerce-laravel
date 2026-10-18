<?php

namespace Tests\Feature;

use App\Http\Controllers\DownloadController;
use App\Models\DownloadableProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderUserLinkageTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrderFor(User $user, Product $product, float $total): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'total_amount' => $total,
            'status' => Order::STATUS_PAID,
        ]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 1, 'price' => $total]);

        return $order;
    }

    public function test_user_orders_relation_resolves_and_scopes_to_the_user(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $mine = $this->paidOrderFor($user, $product, 25);
        Order::create(['customer_email' => 'guest@example.com', 'total_amount' => 5, 'status' => Order::STATUS_PAID]);

        $orders = $user->orders()->get();

        $this->assertTrue($orders->contains('id', $mine->id));
        $this->assertCount(1, $orders);
    }

    public function test_download_is_authorized_for_a_paid_order(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 20]);
        $this->paidOrderFor($user, $product, 20);
        $downloadable = DownloadableProduct::create([
            'product_id' => $product->id,
            'file_url' => 'files/x.zip',
            'download_limit' => 5,
            'downloads_count' => 0,
        ]);

        $method = new \ReflectionMethod(DownloadController::class, 'authorizeDownload');
        $granted = $method->invoke(app(DownloadController::class), $user, $downloadable);

        $this->assertTrue($granted, 'A paid order should grant download access');
    }
}
