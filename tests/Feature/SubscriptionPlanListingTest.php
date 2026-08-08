<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * `/subscriptions` is anonymous by design — it is the pricing list and touches
 * no user data. That makes it the one Stripe-backed route a stranger can call,
 * so what it does per request matters.
 */
class SubscriptionPlanListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unconfigured_install_returns_an_empty_list_rather_than_a_server_error(): void
    {
        config(['services.stripe.secret' => '']);

        $this->getJson('/subscriptions')
            ->assertOk()
            ->assertExactJson(['plans' => []]);
    }

    /**
     * Proves the cache is consulted before Stripe is: the key here is nonsense,
     * so any call to the API would raise AuthenticationException and this would
     * be a 500 instead of the cached payload.
     */
    public function test_a_cached_plan_list_is_served_without_calling_stripe(): void
    {
        config(['services.stripe.secret' => 'sk_test_not_a_real_key']);
        Cache::put('stripe.plans', [['id' => 'plan_cached']], now()->addHour());

        $this->getJson('/subscriptions')
            ->assertOk()
            ->assertExactJson(['plans' => [['id' => 'plan_cached']]]);
    }
}
