<?php

namespace App\Services;

use App\Models\GiftRegistry;
use App\Models\Order;
use App\Models\ProductRating;
use App\Models\ProductReview;
use App\Models\User;

/**
 * Assembles a user's personal data for a GDPR right-of-access / portability export.
 *
 * Every entity is field-whitelisted rather than dumped: credentials, 2FA material and
 * raw payment-method `details` must never leave the system, and a future column added
 * to one of these tables must not silently start appearing in exports.
 *
 * Kept in symmetry with GdprErasureService, and that symmetry is now an assertion
 * rather than an intention: GdprSymmetryTest walks both services and fails when one
 * touches a store of personal data the other does not. It was previously stated here
 * and untrue in both directions — segment memberships were exported and never erased,
 * the wishlist was erased and never exported, and `customer_metrics` was in neither,
 * so the derived profile was invisible to both halves of the person's rights at once.
 *
 * The failure mode is worth naming, because it is not carelessness: each service is
 * read on its own, each looks complete on its own, and nothing put the two lists side
 * by side. A whitelist is only as good as the thing that checks it is exhaustive.
 *
 * Registry access codes (a private-registry secret) and registry purchases (a third
 * party's PII) are deliberately excluded.
 *
 * Covers identity, transactional data, user-authored content, and behavioural/tracking
 * data (browsing history, product interactions, segment memberships). Behavioural data
 * is high-volume; it is exported in full rather than capped, since Art. 15 asks for
 * completeness — trim with a date range here if volume becomes a problem.
 */
class GdprExportService
{
    /**
     * The export spans every store, whatever host the request arrived on.
     *
     * Subject access is about a person, not about a storefront. Scoped to the
     * host the request happened to land on, this would return one merchant's
     * slice and present it as the whole record — a wrong answer rather than a
     * partial one.
     */
    public function export(User $user): array
    {
        return StoreContext::acrossAllStores(fn () => $this->everything($user));
    }

    private function everything(User $user): array
    {
        $customer = $user->customer;

        return [
            'exported_at' => now()->toIso8601String(),
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => optional($user->email_verified_at)->toIso8601String(),
                'created_at' => optional($user->created_at)->toIso8601String(),
            ],
            'customer' => $this->customer($user),
            'orders' => $this->orders($user),
            'payment_methods' => $user->paymentMethods->map(fn ($pm) => [
                'name' => $pm->name,
                'is_default' => (bool) $pm->is_default,
            ])->all(),
            // One stack since ADR 0008, keyed to the customer. The keys keep the
            // names a person understands rather than the table names.
            'reviews' => $customer ? $this->reviews($customer->id) : [],
            'ratings' => $customer ? $this->ratings($customer->id) : [],
            'gift_registries' => $this->giftRegistries($user),
            'wishlist' => $this->wishlist($user),
            'browsing_history' => $this->browsingHistory($user),
            'product_interactions' => $this->productInteractions($user),
            'segments' => $this->segments($user),
            'metrics' => $this->metrics($user),
        ];
    }

    /**
     * The derived profile — the answer to "what have you worked out about me",
     * which is the part of Art. 15 a whitelist is most likely to leave out
     * because no page displays it.
     *
     * `average_order_value` and `lifetime_value` are sums across currencies and
     * stores in this schema, which is a known fault of the source rather than of
     * this export. They are disclosed as held rather than corrected here: an
     * export that silently improves on the stored value is not showing the person
     * what is held about them.
     */
    private function metrics(User $user): ?array
    {
        $metric = $user->customerMetric;
        if ($metric === null) {
            return null;
        }

        return [
            'lifetime_value' => $metric->lifetime_value,
            'average_order_value' => $metric->average_order_value,
            'total_orders' => $metric->total_orders,
            'total_items_purchased' => $metric->total_items_purchased,
            'first_purchase_at' => optional($metric->first_purchase_at)->toIso8601String(),
            'last_purchase_at' => optional($metric->last_purchase_at)->toIso8601String(),
            'days_since_last_purchase' => $metric->days_since_last_purchase,
            'predicted_next_order_value' => $metric->predicted_next_order_value,
            'predicted_next_order_date' => optional($metric->predicted_next_order_date)->toDateString(),
            'customer_segment' => $metric->customer_segment,
            'retention_score' => $metric->retention_score,
        ];
    }

    /** Erasure deletes the wishlist, so Art. 15 has to be able to show it first. */
    private function wishlist(User $user): array
    {
        return $user->wishlist()->latest('id')->get()->map(fn ($w) => [
            'product_id' => $w->product_id,
            'added_at' => optional($w->created_at)->toIso8601String(),
        ])->all();
    }

    private function browsingHistory(User $user): array
    {
        return $user->browsingHistory()->latest('id')->get()->map(fn ($h) => [
            'product_id' => $h->product_id,
            'viewed_at' => optional($h->created_at)->toIso8601String(),
        ])->all();
    }

    private function productInteractions(User $user): array
    {
        return $user->productInteractions()->latest('id')->get()->map(fn ($i) => [
            'product_id' => $i->product_id,
            'interaction_type' => $i->interaction_type,
            'duration' => $i->duration,
            'metadata' => $i->metadata,
            'interacted_at' => optional($i->interacted_at)->toIso8601String(),
        ])->all();
    }

    /** Segment memberships — the segment names only, not the internal matching rules. */
    private function segments(User $user): array
    {
        return $user->customerSegments()->get()->map(fn ($s) => [
            'name' => $s->name,
            'description' => $s->description,
        ])->all();
    }

    private function reviews(int $customerId): array
    {
        return ProductReview::where('customer_id', $customerId)->latest('id')->get()->map(fn (ProductReview $r) => [
            'product_id' => $r->product_id,
            'comments' => $r->comments,
            // Their own review's moderation state is theirs to see: a pending
            // review is data held about them that no page shows them.
            'approved' => (bool) $r->approved,
            'is_verified_purchase' => (bool) $r->is_verified_purchase,
            'created_at' => optional($r->created_at)->toIso8601String(),
        ])->all();
    }

    private function ratings(int $customerId): array
    {
        return ProductRating::where('customer_id', $customerId)->latest('id')->get()->map(fn (ProductRating $r) => [
            'product_id' => $r->product_id,
            'rating' => $r->rating,
            'overall_rating' => $r->overall_rating,
            'quality_rating' => $r->quality_rating,
            'value_rating' => $r->value_rating,
            'price_rating' => $r->price_rating,
            'created_at' => optional($r->created_at)->toIso8601String(),
        ])->all();
    }

    private function giftRegistries(User $user): array
    {
        // access_code (private-registry secret) and purchases (third-party purchaser
        // PII) are intentionally excluded.
        return GiftRegistry::where('user_id', $user->id)->with('items')->latest('id')->get()->map(fn (GiftRegistry $g) => [
            'name' => $g->name,
            'type' => $g->type,
            'event_date' => optional($g->event_date)->toDateString(),
            'message' => $g->message,
            'location' => $g->location,
            'privacy' => $g->privacy,
            'shipping_name' => $g->shipping_name,
            'shipping_address' => $g->shipping_address,
            'shipping_city' => $g->shipping_city,
            'shipping_state' => $g->shipping_state,
            'shipping_postal_code' => $g->shipping_postal_code,
            'shipping_country' => $g->shipping_country,
            'items' => $g->items->map(fn ($i) => [
                'product_id' => $i->product_id,
                'quantity_requested' => $i->quantity_requested,
                'quantity_purchased' => $i->quantity_purchased,
                'priority' => $i->priority,
                'notes' => $i->notes,
            ])->all(),
        ])->all();
    }

    private function customer(User $user): ?array
    {
        $customer = $user->customer;
        if ($customer === null) {
            return null;
        }

        return [
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone_number' => $customer->phone_number,
            'address' => $customer->address,
            'city' => $customer->city,
            'state' => $customer->state,
            'postal_code' => $customer->postal_code,
        ];
    }

    private function orders(User $user): array
    {
        $query = Order::query()->where('user_id', $user->id);
        if ($user->customer !== null) {
            $query->orWhere('customer_id', $user->customer->id);
        }

        return $query->latest('id')->get()->map(fn (Order $o) => [
            'id' => $o->id,
            'order_date' => $o->order_date,
            'total_amount' => $o->total_amount,
            'tax_amount' => $o->tax_amount,
            'shipping_cost' => $o->shipping_cost,
            'discount_amount' => $o->discount_amount,
            'coupon_code' => $o->coupon_code,
            'payment_status' => $o->payment_status,
            'shipping_status' => $o->shipping_status,
            'status' => $o->status,
        ])->all();
    }
}
