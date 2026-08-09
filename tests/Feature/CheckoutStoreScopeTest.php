<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Wave 1.5, step 3 on the checkout path: carts and coupons.
 *
 * A coupon is a merchant's money. `coupons.code` is globally unique today, so
 * nothing stopped a code issued by one merchant from discounting a basket at
 * another — the lookup was by code alone, and every merchant shares the table.
 *
 * A cart is quieter but the same class of defect: items added on one storefront
 * appearing on another means a shopper checks out a competitor's basket.
 */
class CheckoutStoreScopeTest extends TestCase
{
    use RefreshDatabase;

    private function storefront(string $host): Store
    {
        $store = Store::factory()->create();
        $channel = Channel::factory()->create(['store_id' => $store->id]);
        ChannelDomain::factory()->primary()->create(['channel_id' => $channel->id, 'host' => $host]);

        return $store;
    }

    /**
     * `store_id` is written after the fact because it is deliberately not
     * fillable — the trait's `creating` hook is its only writer.
     */
    private function stamp(mixed $model, Store $store): mixed
    {
        $model->forceFill(['store_id' => $store->id])->save();

        return $model;
    }

    private function couponAt(Store $store, string $code): Coupon
    {
        return $this->stamp(Coupon::create([
            'code' => $code,
            'type' => 'percentage',
            'value' => 50,
            'valid_from' => null,
            'valid_until' => null,
        ]), $store);
    }

    /**
     * A guest cart on a storefront: the rows carry the store, and the session
     * carries only the token that claims them.
     */
    private function guestCartAt(Store $store, float $price = 100): void
    {
        $token = 'guest-'.$store->id;

        $this->withSession(['cart_token' => $token]);

        $this->stamp(CartItem::create([
            'guest_token' => $token,
            'product_id' => $this->stamp(Product::factory()->create(), $store)->id,
            'quantity' => 1,
            'price' => $price,
        ]), $store);
    }

    private function cartItemAt(Store $store, User $user, Product $product): CartItem
    {
        return $this->stamp(CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 10,
        ]), $store);
    }

    // --- Coupons ----------------------------------------------------------

    public function test_a_coupon_issued_by_another_merchant_does_not_discount_this_basket(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');

        $this->couponAt($theirs, 'THEIRS50');

        $this->guestCartAt($mine);

        $this->from('http://mine.example.com/cart')
            ->post('http://mine.example.com/cart/apply-coupon', ['coupon_code' => 'THEIRS50'])
            ->assertSessionHas('error')
            ->assertSessionMissing('coupon');
    }

    public function test_a_merchants_own_coupon_still_applies(): void
    {
        $mine = $this->storefront('mine.example.com');

        $this->couponAt($mine, 'MINE50');

        $this->guestCartAt($mine);

        $this->from('http://mine.example.com/cart')
            ->post('http://mine.example.com/cart/apply-coupon', ['coupon_code' => 'MINE50'])
            ->assertSessionHas('success')
            ->assertSessionHas('coupon.code', 'MINE50');
    }

    // --- Carts ------------------------------------------------------------

    public function test_a_cart_does_not_carry_items_added_on_another_storefront(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');
        $shopper = User::factory()->create();

        $here = Product::factory()->create(['store_id' => $mine->id]);
        $elsewhere = Product::factory()->create(['store_id' => $theirs->id]);

        $this->cartItemAt($mine, $shopper, $here);
        $this->cartItemAt($theirs, $shopper, $elsewhere);

        Sanctum::actingAs($shopper);
        $ids = collect($this->getJson('http://mine.example.com/api/cart')->json('data'))->pluck('product_id');

        $this->assertSame([$here->id], $ids->all());
    }

    public function test_adding_to_a_cart_stamps_the_storefront_it_was_added_on(): void
    {
        $mine = $this->storefront('mine.example.com');
        $this->storefront('theirs.example.com');
        $shopper = User::factory()->create();

        $product = Product::factory()->create(['store_id' => $mine->id, 'inventory_count' => 5]);

        Sanctum::actingAs($shopper);
        $this->postJson('http://mine.example.com/api/cart', [
            'product_id' => $product->id,
            'quantity' => 1,
        ])->assertCreated();

        $this->assertSame($mine->id, (int) CartItem::query()->sole()->store_id);
    }

    /**
     * `exists:products,id` in the request rules spans every merchant — validation
     * rules do not run through Eloquent, so no global scope reaches them. The
     * model lookup behind it does, which is what makes this a 404 rather than a
     * cart item pointing at a product this storefront does not sell.
     */
    public function test_another_stores_product_cannot_be_added_to_the_cart(): void
    {
        $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');
        $shopper = User::factory()->create();

        $elsewhere = Product::factory()->create(['store_id' => $theirs->id, 'inventory_count' => 5]);

        Sanctum::actingAs($shopper);
        $this->postJson('http://mine.example.com/api/cart', [
            'product_id' => $elsewhere->id,
            'quantity' => 1,
        ])->assertNotFound();

        $this->assertSame(0, CartItem::query()->count());
    }
}
