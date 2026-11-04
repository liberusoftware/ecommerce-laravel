<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Store;
use App\Services\ChannelResolver;
use App\Services\PaymentGateways\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\StripeClient;
use Tests\TestCase;

/** Stripe is charged in the store's currency, in whole minor units, with the configured key. */
class StripeGatewayTest extends TestCase
{
    use RefreshDatabase;

    private object $calls;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calls = new \ArrayObject;
        $calls = $this->calls;
        $service = new class($calls)
        {
            public function __construct(private \ArrayObject $calls) {}

            public function create(array $params): object
            {
                $this->calls[] = $params;

                return (object) ['id' => 'ch_1', 'status' => 'succeeded'];
            }
        };

        $this->app->bind(StripeClient::class, fn () => new class($service) extends StripeClient
        {
            public function __construct(private object $service)
            {
                parent::__construct('sk_test_x');
            }

            public function __get($name)
            {
                return $this->service;
            }
        });
    }

    public function test_a_charge_is_in_the_store_currency_and_whole_minor_units(): void
    {
        Store::query()->update(['currency_code' => 'GBP']);
        request()->attributes->set(ChannelResolver::ATTRIBUTE, Channel::firstOrFail());

        $result = app(StripeGateway::class)->processPayment(19.99, ['token' => 'tok_visa']);

        $this->assertTrue($result['success']);
        $this->assertSame('gbp', $this->calls[0]['currency']);
        $this->assertSame(1999, $this->calls[0]['amount']);
    }

    public function test_refunds_are_whole_minor_units(): void
    {
        app(StripeGateway::class)->refundPayment('ch_1', 0.29);

        $this->assertSame(29, $this->calls[0]['amount']);
    }

    public function test_the_container_builds_the_client_with_the_configured_secret(): void
    {
        $this->app->forgetInstance(StripeClient::class);
        $this->app->bind(StripeClient::class, fn () => new StripeClient(config('services.stripe.secret')));
        config(['services.stripe.secret' => 'sk_test_configured']);

        $this->assertSame('sk_test_configured', app(StripeClient::class)->getApiKey());
    }
}
