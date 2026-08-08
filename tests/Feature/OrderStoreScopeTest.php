<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Wave 1.5, step 3 on the order and customer tables.
 *
 * The plan's rule: order history scopes to the resolved store. The data is the
 * shopper's, but the surface belongs to the merchant — otherwise merchant A's
 * support staff, looking at a customer's account, see what that person bought
 * from a competitor.
 *
 * Two paths are deliberately exempt, and the exemptions are the substance of
 * this change rather than a footnote to it. Both are cases where the request's
 * host says nothing about which store the work is *about*.
 */
class OrderStoreScopeTest extends TestCase
{
    use RefreshDatabase;

    private string $stripeSecret = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.stripe.webhook.secret' => $this->stripeSecret]);
    }

    private function storefront(string $host): Store
    {
        $store = Store::factory()->create();
        $channel = Channel::factory()->create(['store_id' => $store->id]);
        ChannelDomain::factory()->primary()->create(['channel_id' => $channel->id, 'host' => $host]);

        return $store;
    }

    /**
     * `store_id` is written after the fact rather than passed in, because it is
     * deliberately not fillable on any scoped model: the trait's `creating` hook
     * is its only writer, so no request can post its way into another store.
     */
    private function orderAt(Store $store, User $user, array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'user_id' => $user->id,
            'customer_email' => $user->email,
            'payment_method' => 'stripe',
            'total_amount' => 100,
            'status' => Order::STATUS_PAID,
        ], $overrides));

        $order->forceFill(['store_id' => $store->id])->save();

        return $order;
    }

    public function test_order_history_shows_only_the_resolved_stores_orders(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');
        $shopper = User::factory()->create();

        $here = $this->orderAt($mine, $shopper);
        $elsewhere = $this->orderAt($theirs, $shopper);

        Sanctum::actingAs($shopper);
        $ids = collect($this->getJson('http://mine.example.com/api/orders')->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($here->id));
        $this->assertFalse(
            $ids->contains($elsewhere->id),
            "Merchant A's storefront listed what this shopper bought from merchant B.",
        );
    }

    public function test_an_order_placed_at_another_store_is_not_readable_by_id(): void
    {
        $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');
        $shopper = User::factory()->create();

        $elsewhere = $this->orderAt($theirs, $shopper);

        Sanctum::actingAs($shopper);

        $this->getJson('http://mine.example.com/api/orders/'.$elsewhere->id)->assertNotFound();
    }

    // --- The exemptions ---------------------------------------------------

    /**
     * Stripe posts to one configured endpoint, so the webhook's host resolves to
     * whichever store owns that hostname — not to the store the charge belongs
     * to. Scoped, a confirmation for any other store would find no order, take
     * the null branch, and return 200: money captured, order left pending, and
     * nothing anywhere saying so.
     */
    public function test_a_payment_webhook_reaches_an_order_belonging_to_another_store(): void
    {
        $this->storefront('payments.example.com');
        $theirs = $this->storefront('theirs.example.com');
        $shopper = User::factory()->create();

        $order = $this->orderAt($theirs, $shopper, [
            'status' => Order::STATUS_PENDING,
            'transaction_id' => 'ch_cross_store',
        ]);

        $this->postStripe('payments.example.com', [
            'type' => 'charge.succeeded',
            'data' => ['object' => ['id' => 'ch_cross_store', 'object' => 'charge', 'amount' => 10000, 'amount_refunded' => 0]],
        ])->assertOk();

        $this->assertSame(
            Order::STATUS_PAID,
            $order->fresh()->status,
            'A captured payment left its order pending because the webhook arrived on another store\'s hostname.',
        );
    }

    /**
     * Subject access is about a person, not a storefront. Scoped to whichever
     * host the request landed on, an export returns one merchant's slice and
     * presents it as the whole record — a wrong answer, not a partial one.
     */
    public function test_a_data_export_spans_every_store(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');
        $shopper = User::factory()->create();

        $here = $this->orderAt($mine, $shopper);
        $elsewhere = $this->orderAt($theirs, $shopper);

        $export = $this->actingAs($shopper)
            ->get('http://mine.example.com/account/data-export')
            ->assertOk()
            ->json();

        $ids = collect($export['orders'] ?? [])->pluck('id');

        $this->assertTrue($ids->contains($here->id));
        $this->assertTrue(
            $ids->contains($elsewhere->id),
            'The export omitted orders from another store and still called itself complete.',
        );
    }

    /**
     * An erasure that misses rows is a breach that looks like a completed
     * request. It must not depend on which storefront the person happened to be
     * logged into when they asked.
     */
    public function test_an_erasure_spans_every_store(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');
        $shopper = User::factory()->create(['password' => bcrypt('password')]);

        $here = $this->orderAt($mine, $shopper);
        $elsewhere = $this->orderAt($theirs, $shopper);

        $this->actingAs($shopper)
            ->delete('http://mine.example.com/account', ['password' => 'password'])
            ->assertOk();

        // Orders are kept for accounting and scrubbed in place, so the check is
        // on the personal data rather than on the row's absence.
        $scrubbed = StoreContext::acrossAllStores(
            fn () => Order::whereIn('id', [$here->id, $elsewhere->id])->pluck('customer_email', 'id'),
        );

        $this->assertNotSame($shopper->email, $scrubbed[$elsewhere->id], 'An order at another store kept the erased email.');
        $this->assertSame($scrubbed[$here->id], $scrubbed[$elsewhere->id], 'The two stores were erased differently.');
    }

    /**
     * The suspension is scoped to the callback. Leaking it would turn one
     * exempt path into an application-wide hole that nothing would notice.
     */
    public function test_the_exemption_does_not_outlive_its_callback(): void
    {
        $mine = $this->storefront('mine.example.com');
        $theirs = $this->storefront('theirs.example.com');
        $shopper = User::factory()->create();

        $this->orderAt($mine, $shopper);
        $this->orderAt($theirs, $shopper);

        Sanctum::actingAs($shopper);

        StoreContext::acrossAllStores(fn () => Order::query()->count());

        $this->assertCount(1, $this->getJson('http://mine.example.com/api/orders')->json('data'));
    }

    private function postStripe(string $host, array $event): TestResponse
    {
        $payload = json_encode(array_merge(['id' => 'evt_1', 'object' => 'event', 'created' => time()], $event));
        $ts = time();
        $signature = 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$payload, $this->stripeSecret);

        return $this->call(
            'POST',
            "http://{$host}/stripe/webhook",
            [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload,
        );
    }
}
