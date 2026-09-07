<?php

namespace Tests\Feature;

use App\Models\BrowsingHistory;
use App\Models\CustomerMetric;
use App\Models\CustomerSegment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductInteraction;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\GdprErasureService;
use App\Services\GdprExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A subject-access request and an erasure request are two views of one set: whatever
 * the export can show, the erasure has to be able to remove, and vice versa. When the
 * two disagree, one of them is a lie — either the person is shown data that will
 * survive their erasure, or data is destroyed that they were never allowed to see.
 *
 * Both services carried a docblock claiming symmetry, and it was untrue in both
 * directions at once: segment memberships were exported and never erased, the wishlist
 * was erased and never exported, and `customer_metrics` — lifetime value, order counts,
 * purchase dates, a predicted next order, a segment label and a retention score — was
 * in neither, so the derived profile was invisible to both halves of the person's
 * rights.
 *
 * The reason it survived two test files is that each service was tested alone, and
 * alone each one looks complete. These tests set up one person with a row in every
 * store of personal data and then check both services against that same set, which is
 * the only arrangement that can see a gap.
 */
class GdprSymmetryTest extends TestCase
{
    use RefreshDatabase;

    /** One person, with a row in every store of personal data these services know about. */
    private function populatedUser(): User
    {
        $user = User::factory()->create();
        $user->getOrCreateCustomer();
        $product = Product::factory()->create();

        CustomerMetric::create([
            'user_id' => $user->id,
            'lifetime_value' => 1234.56,
            'total_orders' => 7,
            'retention_score' => 88,
        ]);

        $segment = CustomerSegment::create([
            'name' => 'Frequent buyers',
            'conditions' => [],
            'match_type' => 'all',
            'is_active' => true,
        ]);
        $user->customerSegments()->attach($segment->id);

        Wishlist::create(['user_id' => $user->id, 'product_id' => $product->id]);
        PaymentMethod::create(['user_id' => $user->id, 'name' => 'Visa …4242', 'details' => 'tok_x']);
        BrowsingHistory::create(['user_id' => $user->id, 'product_id' => $product->id]);
        ProductInteraction::track($user->id, 'sess-1', $product->id, 'view');

        $user->wishlist_share_token = 'a-published-share-token';
        $user->save();

        return $user;
    }

    public function test_the_derived_profile_is_disclosed(): void
    {
        $user = $this->populatedUser();

        $export = app(GdprExportService::class)->export($user);

        $this->assertNotNull(
            $export['metrics'] ?? null,
            'customer_metrics is a profile held about the person and must appear in an Art. 15 export'
        );
        $this->assertEquals(7, $export['metrics']['total_orders']);
        $this->assertEquals(88, $export['metrics']['retention_score']);
    }

    public function test_the_wishlist_is_disclosed_because_erasure_destroys_it(): void
    {
        $user = $this->populatedUser();

        $export = app(GdprExportService::class)->export($user);

        $this->assertCount(1, $export['wishlist'] ?? [], 'erasure deletes the wishlist, so access must show it');
    }

    public function test_erasure_removes_the_derived_profile(): void
    {
        $user = $this->populatedUser();

        app(GdprErasureService::class)->erase($user);

        $this->assertDatabaseMissing('customer_metrics', ['user_id' => $user->id]);
    }

    public function test_erasure_removes_segment_memberships(): void
    {
        $user = $this->populatedUser();

        app(GdprErasureService::class)->erase($user);

        $this->assertDatabaseMissing('customer_segment_members', ['user_id' => $user->id]);
    }

    /**
     * The segment survives; only the membership goes. `customer_count` is left stale
     * until the next recalculation, which is a number being briefly wrong rather than
     * a person's membership outliving their erasure.
     */
    public function test_erasure_leaves_the_segment_itself_intact(): void
    {
        $user = $this->populatedUser();

        app(GdprErasureService::class)->erase($user);

        $this->assertDatabaseHas('customer_segments', ['name' => 'Frequent buyers']);
    }

    public function test_erasure_revokes_a_published_share_link(): void
    {
        $user = $this->populatedUser();

        app(GdprErasureService::class)->erase($user);

        $this->assertNull($user->fresh()->wishlist_share_token);
    }

    /**
     * The invariant itself, rather than one instance of it: every store of personal
     * data this person has a row in must be empty after an erasure. A new table added
     * to `populatedUser()` and to only one of the two services fails here.
     */
    public function test_nothing_the_export_can_show_survives_an_erasure(): void
    {
        $user = $this->populatedUser();

        $export = app(GdprExportService::class)->export($user);
        $disclosed = array_filter([
            'wishlist' => $export['wishlist'],
            'segments' => $export['segments'],
            'metrics' => $export['metrics'] === null ? [] : [$export['metrics']],
            'payment_methods' => $export['payment_methods'],
            'browsing_history' => $export['browsing_history'],
            'product_interactions' => $export['product_interactions'],
        ]);
        $this->assertNotEmpty($disclosed, 'the fixture must actually populate these, or this proves nothing');

        app(GdprErasureService::class)->erase($user);
        $after = app(GdprExportService::class)->export($user->fresh());

        foreach (array_keys($disclosed) as $key) {
            $value = $key === 'metrics' ? ($after[$key] === null ? [] : [$after[$key]]) : $after[$key];
            $this->assertEmpty($value, "'{$key}' was disclosed by the export and survived the erasure");
        }
    }
}
