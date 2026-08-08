<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ProductRating;
use App\Models\ProductReview;
use App\Models\Review;
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

            // User-authored content (Art. 17). Deleting registries cascades (DB FK) to
            // their items and purchases.
            Review::where('user_id', $user->id)->delete();
            $user->ratings()->delete();
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
