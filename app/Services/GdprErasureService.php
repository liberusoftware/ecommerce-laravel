<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProductRating;
use App\Models\ProductReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * GDPR right-to-erasure (Art. 17). Anonymises a user rather than hard-deleting them so
 * their orders remain intact for accounting/legal retention while every identifying
 * field is scrubbed. All writes happen in one transaction.
 *
 * Scrubs the core identity, order PII, saved payment methods, behavioural tracking, and
 * user-authored content (reviews, ratings, gift registries). Content is DELETED rather
 * than anonymised: user_id/customer_id are NOT NULL and reviews carry free text, so
 * there is no clean row to keep — product rating/review aggregates simply recompute.
 */
class GdprErasureService
{
    private const REDACTED_EMAIL = 'redacted@anonymized.invalid';

    private const REDACTED = 'REDACTED';

    /**
     * Erasure spans every store, whatever host the request arrived on.
     *
     * This is reachable over HTTP from a storefront, and a storefront resolves a
     * store. Scoped, an erasure would silently miss the person's rows at every
     * other merchant and still report success — a breach that looks like a
     * completed request.
     */
    public function erase(User $user): void
    {
        StoreContext::acrossAllStores(fn () => DB::transaction(function () use ($user) {
            $customer = $user->customer;

            $this->scrubOrders($user, $customer?->id);

            // Personal data with no accounting value — delete outright.
            $user->paymentMethods()->delete();
            $user->browsingHistory()->delete();
            $user->productInteractions()->delete();
            $user->wishlist()->delete();

            // The derived profile. `customer_metrics` holds lifetime value, average
            // order value, order and item counts, first and last purchase dates, a
            // predicted next order value and date, a segment label and a retention
            // score — a behavioural profile, and the row a person is most likely to
            // have meant when they asked to be erased. It survived erasure entirely
            // *and* was absent from the export, so it was invisible to both halves of
            // the person's rights at once.
            //
            // Deleted rather than anonymised, on the same reasoning as browsing
            // history: every column is about the person and none of it has accounting
            // value. It is recomputed from orders by `metrics:update-customers`, and
            // the orders that remain are scrubbed above, so nothing here comes back
            // carrying identity.
            $user->customerMetric()->delete();

            // Segment memberships are a statement about the person, held by somebody
            // else's table. Detached rather than deleted so the segment itself
            // survives; `customer_count` goes stale until the next recalculation,
            // which is a number being briefly wrong rather than a person's membership
            // outliving their erasure.
            $user->customerSegments()->detach();

            // User-authored content (Art. 17). Deleting registries cascades (DB FK) to
            // their items and purchases.
            $user->giftRegistries()->delete();
            if ($customer !== null) {
                ProductReview::where('customer_id', $customer->id)->delete();
                ProductRating::where('customer_id', $customer->id)->delete();
            }

            if ($customer !== null) {
                $customer->update([
                    'first_name' => 'Deleted',
                    'last_name' => 'User',
                    'email' => self::REDACTED_EMAIL,
                    'phone_number' => null,
                    'address' => null,
                    'city' => null,
                    'state' => null,
                    'postal_code' => null,
                ]);
            }

            // Anonymise the account in place (row kept so orders keep their owner link).
            $user->forceFill([
                'name' => 'Deleted User',
                'email' => 'deleted-'.$user->id.'@anonymized.invalid',
                'email_verified_at' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                // A published URL that still resolves to this row is the piece of the
                // erasure that outlives it. The items behind it are gone, so the link
                // renders empty rather than leaking — but a live token pointing at an
                // erased subject is a loose end with no reason to exist.
                'wishlist_share_token' => null,
            ])->save();
        }));
    }

    private function scrubOrders(User $user, ?int $customerId): void
    {
        $query = Order::query()->where('user_id', $user->id);
        if ($customerId !== null) {
            $query->orWhere('customer_id', $customerId);
        }

        $query->update([
            'customer_email' => self::REDACTED_EMAIL,
            'shipping_address' => self::REDACTED,
            'recipient_name' => self::REDACTED,
            'recipient_email' => self::REDACTED_EMAIL,
            'gift_message' => null,
        ]);
    }
}
