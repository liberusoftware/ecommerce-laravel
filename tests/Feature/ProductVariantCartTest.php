<?php

namespace Tests\Feature;

use App\Livewire\ShoppingCart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use App\Services\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\BuildsOptionGroups;
use Tests\Feature\Concerns\BuildsVariants;
use Tests\TestCase;

/**
 * Every cart door — web form, Livewire, REST and GraphQL — treats a variant
 * as the thing bought: one must be chosen, it is priced by the server, it has
 * its own stock, and different variants are different lines.
 */
class ProductVariantCartTest extends TestCase
{
    use BuildsOptionGroups;
    use BuildsVariants;
    use RefreshDatabase;

    private Product $phone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phone = $this->variableProduct(['Storage' => ['128 GB', '256 GB'], 'Colour' => ['Black', 'White']], [
            $this->row(['Storage' => '128 GB', 'Colour' => 'Black'], 799, 3, ['sku' => 'PH-128-B']),
            $this->row(['Storage' => '128 GB', 'Colour' => 'White'], 799, 0, ['sku' => 'PH-128-W']),
            $this->row(['Storage' => '256 GB', 'Colour' => 'Black'], 899, 2, ['sku' => 'PH-256-B']),
            $this->row(['Storage' => '256 GB', 'Colour' => 'White'], 899, 5, ['sku' => 'PH-256-W', 'is_active' => false]),
        ], ['name' => 'Pocket Phone']);
    }

    private function id(string $label): int
    {
        return $this->variantOf($this->phone, $label)->id;
    }

    private function gql(string $query, array $variables = []): array
    {
        return $this->postJson('/api/graphql', ['query' => $query, 'variables' => $variables])->json();
    }

    /** Adds through one door and returns an error message, or null on success. */
    private function addVia(string $door, ?int $variantId, int $quantity = 1, array $options = [], ?Product $product = null): ?string
    {
        $product ??= $this->phone;

        return match ($door) {
            'web' => $this->post(route('cart.add', $product), ['quantity' => $quantity, 'variant_id' => $variantId, 'options' => $options, 'price' => 0.01])
                ->baseResponse->getSession()?->get('error'),
            'livewire' => $this->addViaLivewire($product, $variantId, $quantity, $options),
            'rest' => ($r = $this->postJson('/api/cart', ['product_id' => $product->id, 'variant_id' => $variantId, 'quantity' => $quantity, 'options' => $options, 'price' => 0.01]))->isSuccessful()
                ? null : $r->json('message'),
            'graphql' => $this->gql(
                'mutation($p: Int!, $q: Int!, $v: Int, $o: [Int!]) { addToCart(productId: $p, quantity: $q, variantId: $v, options: $o) { id } }',
                ['p' => $product->id, 'q' => $quantity, 'v' => $variantId, 'o' => $options],
            )['errors'][0]['message'] ?? null,
        };
    }

    private function addViaLivewire(Product $product, ?int $variantId, int $quantity, array $options): ?string
    {
        $before = CartItem::sum('quantity');
        $html = Livewire::test(ShoppingCart::class)
            ->call('addToCart', productId: $product->id, name: 'x', price: 0.01, quantity: $quantity, options: $options, variantId: $variantId)
            ->html();

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
    public function test_a_simple_product_is_unchanged(string $door): void
    {
        $this->signIn($door);
        $mug = Product::factory()->create(['price' => 9.50, 'inventory_count' => 2]);

        $this->assertNull($this->addVia($door, null, 2, product: $mug));
        $this->assertNotNull($this->addVia($door, null, 1, product: $mug));

        $line = CartItem::sole();
        $this->assertEquals(9.50, $line->price);
        $this->assertSame(['', null, 2], [$line->configuration_key, $line->product_variant_id, $line->quantity]);
    }

    #[DataProvider('doors')]
    public function test_a_variable_product_cannot_be_added_without_a_variant(string $door): void
    {
        $this->signIn($door);

        $this->assertStringContainsString('Choose Storage and Colour for Pocket Phone', (string) $this->addVia($door, null));
        $this->assertSame(0, CartItem::count());
    }

    #[DataProvider('doors')]
    public function test_the_variant_price_is_charged_and_any_client_price_ignored(string $door): void
    {
        $this->signIn($door);

        $this->assertNull($this->addVia($door, $this->id('256 GB / Black')));

        $line = CartItem::sole();
        $this->assertEquals(899, $line->price);
        $this->assertSame($this->id('256 GB / Black'), $line->product_variant_id);
    }

    #[DataProvider('doors')]
    public function test_different_variants_are_separate_lines_and_identical_ones_merge(string $door): void
    {
        $this->signIn($door);

        $this->addVia($door, $this->id('128 GB / Black'));
        $this->addVia($door, $this->id('256 GB / Black'));
        $this->addVia($door, $this->id('128 GB / Black'));

        $lines = CartItem::orderBy('id')->get();
        $this->assertSame([[$this->id('128 GB / Black'), 2], [$this->id('256 GB / Black'), 1]], $lines->map(fn ($l) => [$l->product_variant_id, $l->quantity])->all());
        $this->assertEquals([799, 899], $lines->pluck('price')->map(fn ($p) => (float) $p)->all());
    }

    #[DataProvider('doors')]
    public function test_each_variant_has_its_own_stock(string $door): void
    {
        $this->signIn($door);

        $this->assertNull($this->addVia($door, $this->id('128 GB / Black'), 3));
        $this->assertNotNull($this->addVia($door, $this->id('128 GB / Black'), 1));
        $this->assertNull($this->addVia($door, $this->id('256 GB / Black'), 2));
        $this->assertNotNull($this->addVia($door, $this->id('128 GB / White'), 1), 'A variant out of stock cannot be added.');

        $this->assertSame(5, (int) CartItem::sum('quantity'));
    }

    #[DataProvider('doors')]
    public function test_an_unavailable_or_foreign_variant_is_refused(string $door): void
    {
        $this->signIn($door);
        $other = $this->variableProduct(['Size' => ['S']], [$this->row(['Size' => 'S'], 5)]);

        $this->assertStringContainsString('not available at the moment', (string) $this->addVia($door, $this->id('256 GB / White')));
        $this->assertStringContainsString('not offered for Pocket Phone', (string) $this->addVia($door, $other->variants->first()->id));
        $this->assertStringContainsString('has no variants', (string) $this->addVia($door, $this->id('128 GB / Black'), product: Product::factory()->create(['name' => 'Mug', 'inventory_count' => 5])));

        $this->assertSame(0, CartItem::count());
    }

    #[DataProvider('doors')]
    public function test_options_adjust_the_chosen_variants_price_and_are_part_of_the_line(string $door): void
    {
        $this->signIn($door);
        $protection = $this->optionGroup('Protection', 0, 1, ['Case' => 19, 'Two-year warranty' => 49], [$this->phone]);

        $this->addVia($door, $this->id('128 GB / Black'), 1, $this->choiceIds($protection, 'Case'));
        $this->addVia($door, $this->id('128 GB / Black'));
        $this->addVia($door, $this->id('128 GB / Black'), 1, $this->choiceIds($protection, 'Case'));

        $lines = CartItem::orderBy('id')->get();
        $this->assertEquals([818, 799], $lines->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertSame([2, 1], $lines->pluck('quantity')->all());
        $this->assertSame(['Case'], array_column($lines[0]->options, 'name'));
        $this->assertNotSame($lines[0]->configuration_key, $lines[1]->configuration_key);
    }

    public function test_lines_of_one_variant_with_different_options_share_its_stock(): void
    {
        $protection = $this->optionGroup('Protection', 0, 1, ['Case' => 19], [$this->phone]);

        $this->assertNull($this->addVia('web', $this->id('256 GB / Black'), 1, $this->choiceIds($protection, 'Case')));
        $this->assertNull($this->addVia('web', $this->id('256 GB / Black'), 1));
        $this->assertNotNull($this->addVia('web', $this->id('256 GB / Black'), 1));

        [$withCase, $bare] = CartItem::orderBy('id')->get()->all();
        $this->put(route('cart.update', $bare->id), ['quantity' => 2])->assertSessionHas('error', 'Not enough inventory available.');
        $this->assertSame(1, $bare->fresh()->quantity);
    }

    public function test_the_cart_shows_the_variant_and_its_sku(): void
    {
        $this->addVia('web', $this->id('256 GB / Black'));

        $line = collect(app(CartService::class)->contents())->sole();
        $this->assertSame(['Storage: 256 GB, Colour: Black', 'PH-256-B'], [$line['variant'], $line['sku']]);

        Livewire::test(ShoppingCart::class)->assertSee('Storage: 256 GB, Colour: Black');
    }

    public function test_rest_reports_the_variants_own_stock_when_it_runs_short(): void
    {
        $this->signIn('rest');

        $this->postJson('/api/cart', ['product_id' => $this->phone->id, 'variant_id' => $this->id('256 GB / Black'), 'quantity' => 3])
            ->assertStatus(422)
            ->assertJsonPath('available', 2);
    }

    public function test_graphql_cart_lines_carry_the_variant(): void
    {
        $this->signIn('graphql');

        $line = $this->gql('mutation($p: Int!, $v: Int) { addToCart(productId: $p, quantity: 1, variantId: $v) { variantId sku price variantAttributes { name value } } }',
            ['p' => $this->phone->id, 'v' => $this->id('128 GB / Black')])['data']['addToCart'];

        $this->assertSame($this->id('128 GB / Black'), $line['variantId']);
        $this->assertSame('PH-128-B', $line['sku']);
        $this->assertEquals(799, $line['price']);
        $this->assertSame([['name' => 'Storage', 'value' => '128 GB'], ['name' => 'Colour', 'value' => 'Black']], $line['variantAttributes']);
    }

    public function test_signing_in_merges_only_lines_of_the_same_variant(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        app(CartService::class)->add($this->phone, 1, [], null, $this->id('128 GB / Black'));
        Auth::logout();

        $this->addVia('web', $this->id('128 GB / Black'));
        $this->addVia('web', $this->id('256 GB / Black'));
        app(CartService::class)->mergeGuestCartIntoAccount($user);

        $lines = CartItem::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertSame([[$this->id('128 GB / Black'), 2], [$this->id('256 GB / Black'), 1]], $lines->map(fn ($l) => [$l->product_variant_id, $l->quantity])->all());
    }

    public function test_a_choice_group_still_merges_identical_configurations_on_a_variant(): void
    {
        $group = $this->optionGroup('Gift wrap', 0, 2, ['Paper' => 2, 'Ribbon' => 1], [$this->phone]);

        $this->addVia('web', $this->id('128 GB / Black'), 1, $this->choiceIds($group, 'Paper', 'Ribbon'));
        $this->addVia('web', $this->id('128 GB / Black'), 1, array_reverse($this->choiceIds($group, 'Paper', 'Ribbon')));

        $this->assertSame(2, CartItem::sole()->quantity);
    }
}
