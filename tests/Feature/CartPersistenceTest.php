<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * One cart store, for guests and accounts alike.
 *
 * A guest's cart used to live in the session as a plain array, and a signed-in
 * shopper's in `cart_items`, with this service mirroring one into the other. Two
 * stores can disagree, and the web checkout charged from the session copy — the
 * one no API or panel could see. Now everything reads the same rows, and the
 * session holds only a token saying which rows are this visitor's.
 */
class CartPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function saved(User $user, Product $product, int $qty, float $price = 10): CartItem
    {
        return CartItem::create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => $qty,
            'price' => $price,
        ]);
    }

    private function guestCart(Product $product, int $qty, float $price = 5): string
    {
        $token = 'guest-token-'.$product->id;

        CartItem::create([
            'guest_token' => $token,
            'product_id' => $product->id,
            'quantity' => $qty,
            'price' => $price,
        ]);

        Session::put('cart_token', $token);

        return $token;
    }

    public function test_merge_combines_the_guest_and_account_carts(): void
    {
        $user = User::factory()->create();
        $saved = Product::factory()->create();
        $guest = Product::factory()->create();
        $this->saved($user, $saved, 2);
        $this->guestCart($guest, 1);

        app(CartService::class)->mergeGuestCartIntoAccount($user);

        $this->assertEquals(2, CartItem::where('user_id', $user->id)->where('product_id', $saved->id)->value('quantity'));
        $this->assertEquals(1, CartItem::where('user_id', $user->id)->where('product_id', $guest->id)->value('quantity'));
    }

    public function test_merge_sums_quantities_for_the_same_product(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $this->saved($user, $product, 2);
        $this->guestCart($product, 3);

        app(CartService::class)->mergeGuestCartIntoAccount($user);

        // Three while signed out and two while signed in means they wanted five.
        // Either cart winning outright loses something they chose.
        $this->assertEquals(5, CartItem::where('user_id', $user->id)->where('product_id', $product->id)->value('quantity'));
        $this->assertSame(1, CartItem::where('product_id', $product->id)->count());
    }

    public function test_merge_leaves_no_row_claimed_by_both(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $token = $this->guestCart($product, 1);

        app(CartService::class)->mergeGuestCartIntoAccount($user);

        // A row holding both an account and a token is reachable by a stranger
        // who still has the token after the owner signed in.
        $this->assertSame(0, CartItem::where('guest_token', $token)->count());
        $this->assertNull(CartItem::where('user_id', $user->id)->value('guest_token'));
        $this->assertNull(Session::get('cart_token'), 'The next guest on this browser would inherit the account holder’s token.');
    }

    public function test_login_event_merges_the_guest_cart(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $this->guestCart($product, 4);

        event(new Login('web', $user, false));

        $this->assertEquals(4, CartItem::where('user_id', $user->id)->where('product_id', $product->id)->value('quantity'));
    }

    public function test_authenticated_add_persists_the_cart(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['inventory_count' => 10]);

        $this->actingAs($user)->post(route('cart.add', $product), ['quantity' => 2]);

        $this->assertDatabaseHas('cart_items', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    public function test_a_guest_add_is_persisted_against_a_token(): void
    {
        $product = Product::factory()->create(['inventory_count' => 10]);

        // The old behaviour was to persist nothing at all for a guest, which is
        // why the web checkout had to read a session array no other surface
        // could see.
        $this->post(route('cart.add', $product), ['quantity' => 1]);

        $item = CartItem::where('product_id', $product->id)->first();

        $this->assertNotNull($item, 'A guest cart was not persisted.');
        $this->assertNull($item->user_id);
        $this->assertNotNull($item->guest_token);
    }

    public function test_one_guests_cart_is_not_another_guests(): void
    {
        $product = Product::factory()->create(['inventory_count' => 10]);

        $this->post(route('cart.add', $product), ['quantity' => 1]);

        // A second visitor, with no token of their own.
        $this->flushSession();

        $this->get(route('cart.index'))->assertOk();

        $this->assertSame(
            1,
            CartItem::count(),
            'The second visitor was served the first one’s cart, or started writing into it.',
        );
        $this->assertSame(0, app(CartService::class)->count(), 'A stranger could see the first visitor’s cart.');
    }

    /**
     * A broken `belongsTo` does not error, it returns null — and every caller
     * reads that as "this line has no product". Assert the product, not just
     * the absence of an exception.
     */
    public function test_a_cart_line_resolves_its_product(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Brass Kettle']);
        $item = $this->saved($user, $product, 1);

        $this->assertTrue($product->is($item->fresh()->product));

        $this->actingAs($user);
        $contents = app(CartService::class)->contents();
        $this->assertSame('Brass Kettle', $contents[$product->id]['name']);
    }

    /**
     * A saved cart belongs to an account or to a guest token, and to nothing
     * else.
     *
     * `cart_items.session_id` was written by every path and read by none. The
     * API, which has no session and could not leave the column empty, wrote the
     * literal string `'api'` — one identity shared by every API client, in a
     * column shaped like an identity. `guest_token` is not that column back
     * again: it has exactly one writer, one reader, and a session id is
     * deliberately not what goes in it.
     */
    public function test_a_saved_cart_item_is_not_identified_by_a_session_id(): void
    {
        $this->assertFalse(
            Schema::hasColumn('cart_items', 'session_id'),
            'cart_items has a session_id again — something will fill it with a sentinel.',
        );

        // The contrast: an abandoned cart is usually a guest's, and there the
        // session really is what identifies it.
        $this->assertTrue(Schema::hasColumn('abandoned_carts', 'session_id'));
    }
}
