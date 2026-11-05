<?php

namespace Tests\Unit;

use App\Services\PaymentGateways\StripeGateway;
use Tests\TestCase;

class StripeGatewaySubscriptionTest extends TestCase
{
    /** Subscriptions are not wired to Stripe, so the gateway must not report a charge that never happened. */
    public function test_process_subscription_fails_closed(): void
    {
        config(['services.stripe.secret' => 'sk_test_unused']);

        $result = app(StripeGateway::class)->processSubscription('plan_1', []);

        $this->assertFalse($result['success']);
        $this->assertArrayNotHasKey('subscription_id', $result);
    }
}
