<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReviewRequest;
use App\Models\ProductRating;
use App\Models\ProductReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Public review writes, and the moderation queue behind them.
 *
 * Since [ADR 0008](../../../docs/adr/0008-reviews-and-ratings-merge.md) this
 * writes `ProductReview` rather than the retired `Review`: reviews are keyed to
 * the `Customer` who wrote them, not the `User` account, and they are
 * store-scoped, so a review left at one merchant does not appear at another.
 */
class ReviewController extends Controller
{
    /**
     * Handles the request to store a new review.
     *
     * @param  ReviewRequest  $request  The request object containing review details.
     * @return JsonResponse A JSON response indicating success and the saved review.
     */
    public function store(ReviewRequest $request)
    {
        $validatedData = $request->validated();

        // A shopper with an account but no customer record gets one here rather
        // than having their review dropped as unmappable — the backfill ADR 0008
        // insists on, at the point of writing rather than in a migration.
        $customer = Auth::user()->getOrCreateCustomer();

        $alreadyReviewed = ProductReview::where('customer_id', $customer->id)
            ->where('product_id', $validatedData['product_id'])
            ->exists();

        if ($alreadyReviewed) {
            return response()->json(['message' => 'You have already reviewed this product'], 409);
        }

        $review = ProductReview::create([
            'product_id' => $validatedData['product_id'],
            'customer_id' => $customer->id,
            'comments' => $validatedData['review'],
            // Published by a decision, never by arriving.
            'approved' => false,
        ]);

        // The score that came in with the review is a *rating*, and after the
        // merge ratings are their own record — a rating without a review is
        // normal, so a review carrying one writes both. `firstOrCreate`, so a
        // breakdown the shopper already left is not flattened to one number.
        ProductRating::firstOrCreate(
            [
                'customer_id' => $customer->id,
                'product_id' => $validatedData['product_id'],
            ],
            [
                'rating' => $validatedData['rating'],
                'overall_rating' => $validatedData['rating'],
            ],
        );

        return response()->json(['message' => 'Review submitted successfully', 'review' => $review], 201);
    }

    public function approve($id)
    {
        // Publishing a review is moderation — staff only (route is already behind auth).
        abort_unless(Auth::user()->hasRole(['super_admin', 'admin']), 403);

        $review = ProductReview::find($id);
        if (! $review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        $review->approve();

        return response()->json(['message' => 'Review approved successfully']);
    }

    /**
     * The public listing. Unauthenticated, so every field here is a publication
     * decision rather than a serialisation default.
     *
     * It used to be `->with('customer')` and `response()->json($reviews)`.
     * `Customer` declares no `$hidden` and carries `email`, `phone_number`,
     * `address`, `city`, `state` and `postal_code`, so that returned the full
     * postal address of every shopper who ever left a review, to anyone who
     * asked, keyed by an incrementing product id. Nothing consumed the customer
     * object — the eager load served no caller at all.
     *
     * Projected explicitly, and the whitelist is the control: a column added to
     * either table later cannot start appearing here by itself.
     */
    public function show($productId)
    {
        $reviews = ProductReview::where('product_id', $productId)
            ->approved()
            ->with('customer:id,first_name')
            ->get()
            ->map(fn (ProductReview $review) => [
                'id' => $review->id,
                'product_id' => $review->product_id,
                'comments' => $review->comments,
                'is_verified_purchase' => (bool) $review->is_verified_purchase,
                'helpful_votes' => $review->helpful_votes,
                'unhelpful_votes' => $review->unhelpful_votes,
                // A first name is what a review page shows. A surname, an email
                // and an address are not, and neither is the customer id — it
                // joins a person's reviews together across products.
                'author' => $review->customer?->first_name,
                'created_at' => optional($review->created_at)->toIso8601String(),
            ]);

        return response()->json($reviews);
    }

    public function vote(Request $request, $id)
    {
        $review = ProductReview::find($id);
        if (! $review) {
            return response()->json(['message' => 'Review not found'], 404);
        }

        if ($request->vote === 'helpful') {
            $review->helpful_votes++;
        } elseif ($request->vote === 'unhelpful') {
            $review->unhelpful_votes++;
        } else {
            return response()->json(['message' => 'Invalid vote type'], 400);
        }

        $review->save();

        return response()->json(['message' => 'Vote recorded successfully']);
    }
}
