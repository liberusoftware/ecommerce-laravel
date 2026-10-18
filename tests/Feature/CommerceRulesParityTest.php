<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\DropxlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The web cart, the API cart and the GraphQL mutation are three doors onto one
 * cart, and each enforces the same rules: only active products, at the selling
 * price, never more than the stock left after what is already in the cart.
 */
class CommerceRulesParityTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + ['price' => 50, 'inventory_count' => 3]);
    }

    private function gql(string $query, array $variables = []): array
    {
        return $this->postJson('/api/graphql', ['query' => $query, 'variables' => $variables])->json();
    }

    private function addByGraphql(Product $product, int $quantity): array
    {
        return $this->gql('mutation($p: Int!, $q: Int!) { addToCart(productId: $p, quantity: $q) { id } }', ['p' => $product->id, 'q' => $quantity]);
    }

    public function test_the_api_and_graphql_refuse_an_inactive_product(): void
    {
        $draft = $this->product(['status' => Product::STATUS_DRAFT]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/cart', ['product_id' => $draft->id, 'quantity' => 1])->assertNotFound();
        $this->assertNotEmpty($this->addByGraphql($draft, 1)['errors'] ?? []);
        $this->assertSame(0, CartItem::count());
    }

    public function test_every_door_charges_the_sale_price(): void
    {
        $onSale = $this->product(['sale_price' => 30]);

        $this->post(route('cart.add', $onSale))->assertSessionHas('success');
        $this->assertEquals(30, CartItem::query()->whereNull('user_id')->value('price'));

        Sanctum::actingAs($apiUser = User::factory()->create());
        $this->postJson('/api/cart', ['product_id' => $onSale->id, 'quantity' => 1])->assertCreated();
        $this->assertEquals(30, CartItem::where('user_id', $apiUser->id)->value('price'));

        Sanctum::actingAs($gqlUser = User::factory()->create());
        $this->addByGraphql($onSale, 1);
        $this->assertEquals(30, CartItem::where('user_id', $gqlUser->id)->value('price'));
    }

    public function test_every_door_counts_what_is_already_in_the_cart_against_stock(): void
    {
        $scarce = $this->product(['inventory_count' => 3]);

        $this->post(route('cart.add', $scarce), ['quantity' => 2]);
        $this->post(route('cart.add', $scarce), ['quantity' => 2])->assertSessionHas('error');

        Sanctum::actingAs($apiUser = User::factory()->create());
        $this->postJson('/api/cart', ['product_id' => $scarce->id, 'quantity' => 2])->assertCreated();
        $this->postJson('/api/cart', ['product_id' => $scarce->id, 'quantity' => 2])->assertStatus(422);

        Sanctum::actingAs($gqlUser = User::factory()->create());
        $this->addByGraphql($scarce, 2);
        $this->assertNotEmpty($this->addByGraphql($scarce, 2)['errors'] ?? []);
        $this->assertSame(2, CartItem::where('user_id', $gqlUser->id)->value('quantity'));
    }

    public function test_pay_what_you_want_is_never_below_the_minimum_off_the_web(): void
    {
        $donation = $this->product(['pricing_type' => 'donation', 'price' => 0, 'minimum_price' => 5, 'suggested_price' => 8]);

        $this->assertSame(8.0, $donation->sellingPrice());
        $donation->update(['suggested_price' => 2]);
        $this->assertSame(5.0, $donation->fresh()->sellingPrice());
    }

    public function test_a_supplier_import_never_touches_another_stores_product(): void
    {
        Http::fake(['*' => Http::response([])]);
        $theirs = Store::factory()->create();
        $mine = Store::factory()->create();
        $existing = Product::factory()->create(['name' => 'Garden Chair', 'price' => 99]);
        $existing->forceFill(['store_id' => $theirs->id])->save();

        $result = app(DropxlService::class)->importProduct(['name' => 'Garden Chair', 'price' => 10, 'category' => 'Outdoor'], $mine->id);

        $this->assertTrue($result['success']);
        $this->assertEquals(99, $existing->fresh()->price);
        $imported = Product::withoutGlobalScopes()->where('store_id', $mine->id)->sole();
        $this->assertEquals(10, $imported->price);
        $this->assertSame($mine->id, $imported->category->store_id);
    }
}
