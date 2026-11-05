<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Product;
use App\Support\StorefrontTheme;
use App\View\Storefront\ProductPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\BuildsVariants;
use Tests\TestCase;

/** A channel's theme restyles the storefront by overriding views; the commerce behind them is the core's. */
class StorefrontThemeTest extends TestCase
{
    use BuildsVariants;
    use RefreshDatabase;

    private Product $tee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->singleton(StorefrontTheme::class, fn ($app) => new StorefrontTheme($app['view']->getFinder(), base_path('tests/Fixtures/themes')));

        $this->tee = $this->variableProduct(['Size' => ['S', 'M']], [
            $this->row(['Size' => 'S'], 20),
            $this->row(['Size' => 'M'], 22),
        ], ['name' => 'Logo Tee', 'is_featured' => true]);
    }

    private function useTheme(?string $theme): void
    {
        Channel::query()->update(['theme' => $theme ?? StorefrontTheme::DEFAULT]);
    }

    public function test_the_default_theme_renders_the_core_views(): void
    {
        $this->useTheme(null);

        $this->get(route('products.show', $this->tee))
            ->assertOk()
            ->assertSee('name="variant_id"', false)
            ->assertDontSee('data-acme-base-picker', false);
    }

    public function test_a_theme_overrides_a_view_and_inherits_its_parents_and_the_core(): void
    {
        $this->useTheme('acme');

        // acme-base's picker, inherited by acme; the page around it is still the core's.
        $this->get(route('products.show', $this->tee))
            ->assertOk()
            ->assertSee('data-acme-base-picker', false)
            ->assertSee('2 versions')
            ->assertSee('Add to cart');

        $this->assertSame('acme', app(StorefrontTheme::class)->name());
        $this->assertSame(['acme', 'acme-base'], app(StorefrontTheme::class)->chain('acme'));
    }

    public function test_a_themed_card_shows_the_cores_price(): void
    {
        $simple = Product::factory()->create(['name' => 'Brass Kettle', 'price' => 30, 'sale_price' => 24, 'is_featured' => true]);
        $this->useTheme('acme');

        $this->get(route('home'))->assertOk()
            ->assertSee('data-acme-card', false)
            ->assertSeeText('Brass Kettle '.app(ProductPresenter::class)->card($simple)['price']);
    }

    public function test_the_next_request_on_another_theme_does_not_keep_the_last_ones_views(): void
    {
        $this->useTheme('acme');
        $this->get(route('products.show', $this->tee))->assertSee('data-acme-base-picker', false);

        $this->useTheme(null);
        $this->get(route('products.show', $this->tee))->assertDontSee('data-acme-base-picker', false);
    }

    public function test_an_unknown_or_unsafe_theme_falls_back_to_the_core(): void
    {
        foreach (['no-such-theme', '../acme', 'Acme'] as $theme) {
            $this->useTheme($theme);

            $this->get(route('products.show', $this->tee))
                ->assertOk()
                ->assertDontSee('data-acme-base-picker', false);
        }
    }

    public function test_the_contract_gives_themes_numbers_to_total_with(): void
    {
        $variants = app(ProductPresenter::class)->variantPicker($this->tee->fresh())['variants'];

        $this->assertSame([20.0, 22.0], array_column($variants, 'amount'));
    }

    public function test_any_view_a_theme_overrides_can_name_the_store(): void
    {
        $this->useTheme('acme');

        $this->get(route('products.search'))->assertOk()
            ->assertSee('data-acme-search', false)
            ->assertSee(Channel::query()->sole()->store->name);
    }

    public function test_the_storefront_names_the_merchant_not_the_platform(): void
    {
        config(['app.name' => 'Liberu Platform']);
        Channel::query()->sole()->store->update(['name' => 'Corner Phones']);

        foreach ([route('home'), route('products.index'), route('login')] as $url) {
            $this->get($url)->assertOk()->assertSee('Corner Phones')->assertDontSee('Liberu Platform');
        }
    }

    public function test_a_theme_overrides_vendor_views_and_the_next_request_does_not_keep_them(): void
    {
        $theme = app(StorefrontTheme::class);
        $finder = app('view')->getFinder();

        $theme->apply('acme');
        $this->assertSame(base_path('tests/Fixtures/themes/acme/resources/views/vendor/pagination/tailwind.blade.php'), $finder->find('pagination::tailwind'));

        $theme->apply(null);
        $this->assertStringNotContainsString('Fixtures/themes', $finder->find('pagination::tailwind'));
    }

    public function test_assets_come_from_the_nearest_theme_that_declares_them(): void
    {
        $theme = app(StorefrontTheme::class);

        $theme->apply('acme');
        $this->assertSame(['themes/acme/resources/css/app.css', 'themes/acme/resources/js/app.js'], $theme->entries());

        $theme->apply('acme-base');
        $this->assertSame(['resources/css/app.css', 'resources/js/app.js'], $theme->entries());
    }
}
