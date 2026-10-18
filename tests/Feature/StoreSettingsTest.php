<?php

namespace Tests\Feature;

use App\Interfaces\PaymentGatewayInterface;
use App\Mail\ContactMessage;
use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Services\PaymentGateways\StripeGateway;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** A merchant's store settings reach their own storefront and nobody else's. */
class StoreSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function storefront(string $host, array $settings = []): Store
    {
        $store = Store::factory()->create($settings);
        $channel = Channel::factory()->create(['store_id' => $store->id]);
        ChannelDomain::factory()->primary()->create(['channel_id' => $channel->id, 'host' => $host]);

        return $store;
    }

    private function productAt(Store $store, array $attributes = []): Product
    {
        $product = Product::factory()->create($attributes + ['inventory_count' => 5]);
        $product->forceFill(['store_id' => $store->id])->save();

        return $product;
    }

    private function fakeStripe(): void
    {
        $this->app->instance(StripeGateway::class, new class implements PaymentGatewayInterface
        {
            public function processPayment(float $amount, array $paymentDetails): array
            {
                return ['success' => true, 'transaction_id' => 'ch_ok'];
            }

            public function processSubscription(string $planId, array $subscriptionDetails): array
            {
                return ['success' => true];
            }

            public function refundPayment(string $transactionId, float $amount): array
            {
                return ['success' => true];
            }
        });
    }

    public function test_each_storefront_shows_its_own_identity(): void
    {
        $this->storefront('acme.test', [
            'name' => 'Acme Outdoors', 'email' => 'hello@acme.test', 'phone' => '+44 1234 567890',
            'tagline' => 'Kit for wet weekends', 'footer_text' => 'Acme Outdoors Ltd',
            'social_links' => ['instagram' => 'https://instagram.com/acme'],
        ]);
        $this->storefront('rival.test', ['name' => 'Rival Goods', 'email' => 'hi@rival.test']);

        $this->get('http://acme.test/')
            ->assertOk()
            ->assertSee('Acme Outdoors')
            ->assertSee('hello@acme.test')
            ->assertSee('+44 1234 567890')
            ->assertSee('Kit for wet weekends')
            ->assertSee('https://instagram.com/acme')
            ->assertDontSee('Rival Goods')
            ->assertDontSee('hi@rival.test');
    }

    public function test_another_stores_product_is_not_found_on_this_host(): void
    {
        $this->storefront('a.test');
        $theirs = $this->productAt(Store::factory()->create(), ['name' => 'Their Thing']);

        $this->get('http://a.test/products/'.$theirs->slug)->assertNotFound();
        $this->post('http://a.test/cart/add/'.$theirs->slug)->assertNotFound();
    }

    public function test_prices_use_the_stores_currency_and_locale(): void
    {
        $store = $this->storefront('euro.test', ['currency_code' => 'EUR', 'locale' => 'de_DE']);
        $product = $this->productAt($store, ['price' => 1234.5]);

        $this->get('http://euro.test/products/'.$product->slug)
            ->assertOk()
            ->assertSee('1.234,50')
            ->assertSee('€')
            ->assertSee('lang="de-DE"', false);
    }

    public function test_an_order_keeps_the_currency_its_store_charged_in(): void
    {
        Notification::fake();
        $this->fakeStripe();
        $store = $this->storefront('pounds.test', ['currency_code' => 'GBP']);
        $product = $this->productAt($store, ['price' => 20, 'is_downloadable' => true]);

        $this->withStoredCart([$product->id => ['price' => 20, 'quantity' => 1]], store: $store)
            ->post('http://pounds.test/checkout/process', [
                'email' => 'buyer@example.com',
                'country' => 'GB',
                'payment_method' => 'stripe',
                'stripeToken' => 'tok_visa',
            ]);

        $order = Order::withoutGlobalScopes()->sole();
        $this->assertSame('GBP', $order->currency_code);

        $store->update(['currency_code' => 'USD']);
        $this->assertSame('GBP', $order->fresh()->currency_code);
    }

    public function test_checkout_refuses_a_gateway_the_store_does_not_accept(): void
    {
        $this->fakeStripe();
        $store = $this->storefront('paypal-only.test', ['payment_gateways' => ['paypal']]);
        $product = $this->productAt($store, ['price' => 20, 'is_downloadable' => true]);

        $this->withStoredCart([$product->id => ['price' => 20, 'quantity' => 1]], store: $store)
            ->post('http://paypal-only.test/checkout/process', [
                'email' => 'buyer@example.com',
                'country' => 'GB',
                'payment_method' => 'stripe',
                'stripeToken' => 'tok_visa',
            ])
            ->assertSessionHas('error', 'That payment method is not available for this store.');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_the_checkout_page_offers_only_accepted_gateways(): void
    {
        $store = $this->storefront('paypal-only.test', ['payment_gateways' => ['paypal']]);
        $product = $this->productAt($store, ['price' => 20]);

        $this->withStoredCart([$product->id => ['price' => 20, 'quantity' => 1]], store: $store)
            ->get('http://paypal-only.test/checkout')
            ->assertOk()
            ->assertSee('id="tab-paypal"', false)
            ->assertDontSee('id="tab-stripe"', false)
            ->assertDontSee('js.stripe.com');
    }

    public function test_tax_inclusive_display_is_a_store_setting(): void
    {
        config(['ecommerce.display_prices_with_tax' => true]);
        $store = $this->storefront('net.test', ['display_prices_with_tax' => false]);
        $product = $this->productAt($store, ['price' => 50]);

        $this->get('http://net.test/products/'.$product->slug)->assertOk()->assertDontSee('inc. tax');
    }

    public function test_contact_messages_go_to_the_stores_inbox(): void
    {
        Mail::fake();
        $this->storefront('acme.test', ['email' => 'hello@acme.test']);

        $this->post('http://acme.test/contact', [
            'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Do you ship to Ireland at all?',
        ]);

        Mail::assertSent(ContactMessage::class, fn (ContactMessage $mail) => $mail->hasTo('hello@acme.test'));
    }

    public function test_contact_messages_fall_back_to_the_platform_inbox(): void
    {
        Mail::fake();
        $this->storefront('quiet.test', ['email' => null]);

        $this->post('http://quiet.test/contact', [
            'name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Do you ship to Ireland at all?',
        ]);

        Mail::assertSent(ContactMessage::class, fn (ContactMessage $mail) => $mail->hasTo(app(GeneralSettings::class)->site_email));
    }

    public function test_a_new_store_takes_the_platform_defaults(): void
    {
        $settings = app(GeneralSettings::class);
        $settings->default_currency = 'JPY';
        $settings->default_locale = 'ja';
        $settings->save();

        $store = Store::factory()->create();

        $this->assertSame('JPY', $store->currency_code);
        $this->assertSame('ja', $store->locale);
    }

    public function test_the_main_site_can_be_moved_to_another_store(): void
    {
        $original = Store::mainSite();
        $other = Store::factory()->create(['name' => 'New Flagship']);

        $other->makeMainSite();

        $this->assertTrue($other->servesMainSite());
        $this->assertFalse($original->fresh()->servesMainSite());
        $this->assertSame($other->id, Store::mainSite()->id);
        $this->get('http://'.Store::mainSiteHost().'/')->assertOk()->assertSee('New Flagship');
    }
}
