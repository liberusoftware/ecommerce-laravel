<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StockNotification;
use App\Models\User;
use App\Notifications\ProductBackInStockNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BackInStockNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function subscriber(Product $product, array $overrides = []): StockNotification
    {
        return StockNotification::create(array_merge([
            'product_id' => $product->id,
            'email' => 'want@example.com',
            'notification_type' => 'back_in_stock',
            'notified' => false,
        ], $overrides));
    }

    public function test_guest_can_subscribe_to_back_in_stock(): void
    {
        $product = Product::factory()->create(['inventory_count' => 0]);

        $this->postJson(route('products.notify-me', $product), ['email' => 'want@example.com'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('stock_notifications', [
            'product_id' => $product->id,
            'email' => 'want@example.com',
            'notification_type' => 'back_in_stock',
            'notified' => false,
        ]);
    }

    public function test_subscribing_twice_does_not_duplicate(): void
    {
        $product = Product::factory()->create(['inventory_count' => 0]);
        $payload = ['email' => 'want@example.com'];

        $this->postJson(route('products.notify-me', $product), $payload)->assertStatus(200);
        $this->postJson(route('products.notify-me', $product), $payload)->assertStatus(200);

        $this->assertSame(1, StockNotification::where('product_id', $product->id)->count());
    }

    public function test_restock_notifies_pending_subscribers_and_marks_them_notified(): void
    {
        Notification::fake();
        $product = Product::factory()->create(['inventory_count' => 0]);
        $sub = $this->subscriber($product);

        $product->increment('inventory_count', 5); // restock via model instance (admin/refund path)

        Notification::assertSentOnDemand(
            ProductBackInStockNotification::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'want@example.com'
        );
        $this->assertTrue((bool) $sub->fresh()->notified);
    }

    public function test_already_notified_subscriber_is_not_notified_again(): void
    {
        Notification::fake();
        $product = Product::factory()->create(['inventory_count' => 0]);
        $this->subscriber($product, ['email' => 'done@example.com', 'notified' => true, 'notified_at' => now()]);

        $product->increment('inventory_count', 5);

        Notification::assertNothingSent();
    }

    public function test_authenticated_subscriber_notified_via_user(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $product = Product::factory()->create(['inventory_count' => 0]);
        $this->subscriber($product, ['user_id' => $user->id, 'email' => $user->email]);

        $product->increment('inventory_count', 5);

        Notification::assertSentTo($user, ProductBackInStockNotification::class);
    }

    public function test_no_notification_when_stock_stays_zero(): void
    {
        Notification::fake();
        $product = Product::factory()->create(['inventory_count' => 0]);
        $this->subscriber($product);

        $product->update(['name' => 'Renamed Product']); // unrelated change, stock still 0

        Notification::assertNothingSent();
    }

    public function test_signed_in_shopper_subscribes_without_supplying_an_email(): void
    {
        $product = Product::factory()->create(['inventory_count' => 0]);
        $user = User::factory()->create(['email' => 'buyer@example.com']);

        $this->actingAs($user)
            ->post(route('products.notify-me', $product))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('stock_notifications', [
            'product_id' => $product->id,
            'email' => 'buyer@example.com',
            'user_id' => $user->id,
            'notified' => false,
        ]);
    }

    public function test_a_form_post_redirects_back_with_a_confirmation(): void
    {
        $product = Product::factory()->create(['inventory_count' => 0]);

        $this->from(route('products.show', $product))
            ->post(route('products.notify-me', $product), ['email' => 'want@example.com'])
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHas('success');
    }

    public function test_a_guest_form_post_without_an_email_reports_the_error(): void
    {
        $product = Product::factory()->create(['inventory_count' => 0]);

        $this->from(route('products.show', $product))
            ->post(route('products.notify-me', $product))
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHasErrors('email');

        $this->assertSame(0, StockNotification::count());
    }

    public function test_a_posted_user_id_does_not_let_a_guest_skip_the_email(): void
    {
        $product = Product::factory()->create(['inventory_count' => 0]);
        $someoneElse = User::factory()->create();

        $this->post(route('products.notify-me', $product), ['user_id' => $someoneElse->id])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, StockNotification::count());
    }

    public function test_json_clients_still_get_json(): void
    {
        $product = Product::factory()->create(['inventory_count' => 0]);

        $this->postJson(route('products.notify-me', $product), ['email' => 'api@example.com'])
            ->assertOk()
            ->assertJson(['success' => true]);
        $this->postJson(route('products.notify-me', $product))->assertUnprocessable();
    }
}
