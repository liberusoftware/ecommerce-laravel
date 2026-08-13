<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/cart/apply-coupon` is unauthenticated, so its refusal is an oracle.
 *
 * The route was already throttled against exactly this — its comment says
 * "distinguishable valid/invalid responses make this brute-forceable to
 * enumerate discount codes" — and the responses stayed distinguishable
 * anyway. There were three of them: a code that does not exist, a code that
 * exists but is spent, and a code that exists, is live, and whose configured
 * minimum spend was printed back to a caller who does not hold it.
 *
 * A throttle limits the rate at which an oracle can be asked; it does not stop
 * it being one. The codes merchants actually issue — SUMMER10, WELCOME —
 * are guessable inside ten attempts.
 *
 * The rule here is the one the gift-card module already settled: enumeration
 * is closed by making every wrong answer the same answer.
 */
class PromoCodeEnumerationTest extends TestCase
{
    use RefreshDatabase;

    /** The minimum spend that must never reach an unauthenticated caller. */
    private const MIN_SPEND = 250.00;

    public function test_every_refusal_is_the_same_refusal(): void
    {
        $this->fillACart();

        Coupon::create([
            'code' => 'EXPIRED',
            'type' => 'percentage', 'value' => 10,
            'valid_from' => now()->subDays(30), 'valid_until' => now()->subDay(),
        ]);
        Coupon::create([
            'code' => 'EXHAUSTED',
            'type' => 'percentage', 'value' => 10,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addDay(),
            'max_uses' => 0,
        ]);
        Coupon::create([
            'code' => 'NOTYET',
            'type' => 'percentage', 'value' => 10,
            'valid_from' => now()->addDay(), 'valid_until' => now()->addDays(30),
        ]);
        Coupon::create([
            'code' => 'BIGSPEND',
            'type' => 'percentage', 'value' => 10,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addDay(),
            'min_purchase_amount' => self::MIN_SPEND,
        ]);

        // A code that does not exist is the baseline every other refusal must
        // be indistinguishable from.
        $baseline = $this->refusalFor('NO-SUCH-CODE');

        $this->assertNotSame('', $baseline, 'the unknown-code path must refuse at all');

        foreach (['EXPIRED', 'EXHAUSTED', 'NOTYET', 'BIGSPEND'] as $code) {
            $this->assertSame(
                $baseline,
                $this->refusalFor($code),
                "refusing `{$code}` tells a stranger the code exists"
            );
        }
    }

    public function test_a_coupons_minimum_spend_is_never_disclosed(): void
    {
        $this->fillACart();

        Coupon::create([
            'code' => 'BIGSPEND',
            'type' => 'percentage', 'value' => 10,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addDay(),
            'min_purchase_amount' => self::MIN_SPEND,
        ]);

        $refusal = $this->refusalFor('BIGSPEND');

        // Both renderings, because the leak was a `sprintf('%.2f')` and a
        // future one need not be.
        $this->assertStringNotContainsString('250', $refusal);
        $this->assertStringNotContainsString('250.00', $refusal);
    }

    public function test_a_usable_coupon_still_applies(): void
    {
        // The point is that refusals are uniform, not that everything refuses.
        $this->fillACart();

        Coupon::create([
            'code' => 'GOOD10',
            'type' => 'percentage', 'value' => 10,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addDay(),
        ]);

        $response = $this->post(route('cart.apply-coupon'), ['coupon_code' => 'GOOD10']);

        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');
        $this->assertSame('GOOD10', session('coupon')['code']);
    }

    private function refusalFor(string $code): string
    {
        // Forget the two flash keys rather than flushing: the guest cart is
        // identified by a token held in this same session, and flushing it
        // would empty the cart the next request needs.
        session()->forget(['error', 'success']);

        $this->post(route('cart.apply-coupon'), ['coupon_code' => $code])
            ->assertSessionMissing('success');

        return (string) session('error');
    }

    /**
     * The route refuses an empty cart before it ever looks at the code, so
     * every case here needs a line in the cart or it proves nothing.
     */
    private function fillACart(): void
    {
        $category = ProductCategory::create([
            'name' => 'Test Category',
            'slug' => 'enumeration-'.uniqid(),
        ]);

        $product = Product::create([
            'name' => 'Test Product',
            'slug' => 'enumeration-'.uniqid(),
            'price' => 100.00,
            'category_id' => $category->id,
            'inventory_count' => 100,
            'is_downloadable' => false,
        ]);

        $this->post(route('cart.add', $product), ['quantity' => 1])
            ->assertSessionHas('success');
    }
}
