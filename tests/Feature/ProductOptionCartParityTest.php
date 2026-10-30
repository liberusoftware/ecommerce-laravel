<?php

namespace Tests\Feature;

use App\Livewire\ShoppingCart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\BuildsOptionGroups;
use Tests\TestCase;

/**
 * Every cart door — web form, Livewire, REST and GraphQL — applies the same
 * option rules: the server prices the choices, differently configured lines
 * stay apart, identical ones merge, and stock counts every line of a product.
 */
class ProductOptionCartParityTest extends TestCase
{
    use BuildsOptionGroups;
    use RefreshDatabase;

    private Product $burger;

    private ProductOptionGroup $extras;

    private ProductOptionGroup $bun;

    protected function setUp(): void
    {
        parent::setUp();
        $this->burger = Product::factory()->create(['name' => 'Smashdown', 'price' => 5.50, 'inventory_count' => 5]);
        $this->bun = $this->optionGroup('Bun', 1, 1, ['Brioche' => 0, 'Seeded' => 0], [$this->burger]);
        $this->extras = $this->optionGroup('Extras', 0, 2, ['Cheese' => 1.50, 'Bacon' => 1.00, 'Egg' => 0.80], [$this->burger]);
    }

    /** @return list<int> */
    private function picks(string ...$names): array
    {
        return array_map(fn (string $n) => $this->choice($n === 'Brioche' || $n === 'Seeded' ? $this->bun : $this->extras, $n)->id, $names);
    }

    private function gql(string $query, array $variables = []): array
    {
        return $this->postJson('/api/graphql', ['query' => $query, 'variables' => $variables])->json();
    }

    /**
     * Adds through one door and returns an error message, or null on success.
     *
     * @param  list<int>  $choices
     */
    private function addVia(string $door, array $choices, int $quantity = 1): ?string
    {
        return match ($door) {
            'web' => $this->post(route('cart.add', $this->burger), ['quantity' => $quantity, 'options' => $choices, 'price' => 0.01])
                ->baseResponse->getSession()?->get('error'),
            'livewire' => $this->addViaLivewire($choices, $quantity),
            'rest' => ($r = $this->postJson('/api/cart', ['product_id' => $this->burger->id, 'quantity' => $quantity, 'options' => $choices, 'price' => 0.01]))->isSuccessful()
                ? null : $r->json('message'),
            'graphql' => $this->gql(
                'mutation($p: Int!, $q: Int!, $o: [Int!]) { addToCart(productId: $p, quantity: $q, options: $o) { id } }',
                ['p' => $this->burger->id, 'q' => $quantity, 'o' => $choices],
            )['errors'][0]['message'] ?? null,
        };
    }

    private function addViaLivewire(array $choices, int $quantity): ?string
    {
        $before = CartItem::sum('quantity');
        $html = Livewire::test(ShoppingCart::class)
            ->call('addToCart', productId: $this->burger->id, name: 'x', price: 0.01, quantity: $quantity, options: $choices)
            ->html();

        // The component flashes its refusal into its own render.
        return CartItem::sum('quantity') > $before ? null : html_entity_decode(strip_tags($html));
    }

    private function signIn(string $door): ?User
    {
        if ($door === 'rest' || $door === 'graphql') {
            Sanctum::actingAs($user = User::factory()->create());

            return $user;
        }

        return null;
    }

    public static function doors(): array
    {
        return ['web' => ['web'], 'livewire' => ['livewire'], 'rest' => ['rest'], 'graphql' => ['graphql']];
    }

    #[DataProvider('doors')]
    public function test_the_server_prices_the_choices_and_ignores_any_client_price(string $door): void
    {
        $this->signIn($door);

        $this->assertNull($this->addVia($door, $this->picks('Seeded', 'Cheese', 'Bacon')));

        $line = CartItem::sole();
        $this->assertEquals(8.00, $line->price);
        $this->assertSame(['Seeded', 'Cheese', 'Bacon'], array_column($line->options, 'name'));
    }

    #[DataProvider('doors')]
    public function test_different_choices_are_separate_lines_and_identical_ones_merge(string $door): void
    {
        $this->signIn($door);

        $this->addVia($door, $this->picks('Brioche', 'Cheese'));
        $this->addVia($door, $this->picks('Brioche'));
        $this->addVia($door, array_reverse($this->picks('Brioche', 'Cheese')));

        $lines = CartItem::orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame([2, 1], $lines->pluck('quantity')->all());
        $this->assertEquals([7.00, 5.50], $lines->pluck('price')->map(fn ($p) => (float) $p)->all());
    }

    #[DataProvider('doors')]
    public function test_invalid_selections_are_refused_and_nothing_is_written(string $door): void
    {
        $this->signIn($door);
        $other = Product::factory()->create(['price' => 1]);
        $foreign = $this->optionGroup('Dip', 0, 1, ['Curry' => 0.50], [$other]);

        $this->assertStringContainsString('Choose an option for Bun', (string) $this->addVia($door, $this->picks('Cheese')));
        $this->assertStringContainsString('Choose only one option for Bun', (string) $this->addVia($door, $this->picks('Brioche', 'Seeded')));
        $this->assertStringContainsString('no more than 2', (string) $this->addVia($door, $this->picks('Brioche', 'Cheese', 'Bacon', 'Egg')));
        $this->choice($this->extras, 'Egg')->update(['is_available' => false]);
        $this->assertStringContainsString('Egg is not available', (string) $this->addVia($door, $this->picks('Brioche', 'Egg')));
        $this->assertStringContainsString('not offered', (string) $this->addVia($door, [...$this->picks('Brioche'), $this->choice($foreign, 'Curry')->id]));

        $this->assertSame(0, CartItem::count());
    }

    #[DataProvider('doors')]
    public function test_stock_counts_every_line_of_the_product(string $door): void
    {
        $this->signIn($door);

        $this->assertNull($this->addVia($door, $this->picks('Brioche'), 3));
        $this->assertNotNull($this->addVia($door, $this->picks('Seeded'), 3));
        $this->assertNull($this->addVia($door, $this->picks('Seeded'), 2));

        $this->assertSame(5, (int) CartItem::sum('quantity'));
    }

    public function test_web_update_and_remove_touch_only_the_line_addressed(): void
    {
        $this->addVia('web', $this->picks('Brioche'));
        $this->addVia('web', $this->picks('Seeded'));
        [$brioche, $seeded] = CartItem::orderBy('id')->get()->all();

        $this->put(route('cart.update', $seeded->id), ['quantity' => 5])->assertSessionHas('error', 'Not enough inventory available.');
        $this->put(route('cart.update', $seeded->id), ['quantity' => 3])->assertSessionHas('success');
        $this->delete(route('cart.remove', $brioche->id))->assertSessionHas('success');

        $this->assertSame([[$seeded->id, 3]], CartItem::all()->map(fn ($l) => [$l->id, $l->quantity])->all());
    }

    public function test_livewire_update_and_remove_touch_only_the_line_addressed(): void
    {
        $this->addVia('livewire', $this->picks('Brioche'));
        $this->addVia('livewire', $this->picks('Seeded'));
        [$brioche, $seeded] = CartItem::orderBy('id')->get()->all();

        Livewire::test(ShoppingCart::class)
            ->call('updateQuantity', $seeded->id, 3)
            ->call('removeItem', $brioche->id)
            ->assertSee('Bun: Seeded');

        $this->assertSame([[$seeded->id, 3]], CartItem::all()->map(fn ($l) => [$l->id, $l->quantity])->all());
    }

    public function test_rest_update_and_remove_touch_only_the_line_addressed(): void
    {
        $this->signIn('rest');
        $this->addVia('rest', $this->picks('Brioche'));
        $this->addVia('rest', $this->picks('Seeded'));
        [$brioche, $seeded] = CartItem::orderBy('id')->get()->all();

        $this->putJson("/api/cart/{$seeded->id}", ['quantity' => 5])->assertStatus(422);
        $this->putJson("/api/cart/{$seeded->id}", ['quantity' => 3])->assertOk()->assertJsonPath('data.options.0.name', 'Seeded');
        $this->deleteJson("/api/cart/{$brioche->id}")->assertOk();

        $this->assertSame([[$seeded->id, 3]], CartItem::all()->map(fn ($l) => [$l->id, $l->quantity])->all());
    }

    public function test_graphql_update_and_remove_touch_only_the_line_addressed(): void
    {
        $this->signIn('graphql');
        $this->addVia('graphql', $this->picks('Brioche'));
        $this->addVia('graphql', $this->picks('Seeded'));
        [$brioche, $seeded] = CartItem::orderBy('id')->get()->all();

        $over = $this->gql('mutation($id: Int!) { updateCartItem(id: $id, quantity: 5) { id } }', ['id' => $seeded->id]);
        $this->assertStringContainsString('exceeds available stock', $over['errors'][0]['message']);
        $updated = $this->gql('mutation($id: Int!) { updateCartItem(id: $id, quantity: 3) { quantity options { group name priceAdjustment } } }', ['id' => $seeded->id]);
        $this->assertEquals([['group' => 'Bun', 'name' => 'Seeded', 'priceAdjustment' => 0]], $updated['data']['updateCartItem']['options']);
        $this->assertTrue($this->gql('mutation($id: Int!) { removeCartItem(id: $id) }', ['id' => $brioche->id])['data']['removeCartItem']);

        $this->assertSame([[$seeded->id, 3]], CartItem::all()->map(fn ($l) => [$l->id, $l->quantity])->all());
    }

    public function test_graphql_accepts_no_price_argument(): void
    {
        $this->signIn('graphql');

        $data = $this->gql('mutation($p: Int!) { addToCart(productId: $p, quantity: 1, price: 0.01) { id } }', ['p' => $this->burger->id]);

        $this->assertNotEmpty($data['errors']);
        $this->assertSame(0, CartItem::count());
    }

    public function test_signing_in_merges_only_identically_configured_lines(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        app(CartService::class)->add($this->burger, 1, $this->picks('Brioche', 'Cheese'));
        Auth::logout();

        $this->addVia('web', $this->picks('Cheese', 'Brioche'));
        $this->addVia('web', $this->picks('Seeded'));
        app(CartService::class)->mergeGuestCartIntoAccount($user);

        $lines = CartItem::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame([2, 1], $lines->pluck('quantity')->all());
        $this->assertSame(0, CartItem::whereNotNull('guest_token')->count());
    }
}
