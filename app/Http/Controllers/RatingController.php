<?php

namespace App\Http\Controllers;

use App\Http\Requests\RatingRequest;
use App\Models\ProductRating;
use Illuminate\Support\Facades\Auth;

/**
 * Ratings, which are their own concept — a rating without a review is normal,
 * not an incomplete record ([ADR 0008](../../../docs/adr/0008-reviews-and-ratings-merge.md)).
 *
 * Writes `ProductRating` since the merge: keyed to the `Customer`, store-scoped.
 */
class RatingController extends Controller
{
    public function store(RatingRequest $request)
    {
        // Same backfill as a review: the account exists, the customer record may
        // not, and dropping the rating rather than creating one is not a choice
        // this controller gets to make.
        $customer = Auth::user()->getOrCreateCustomer();

        $alreadyRated = ProductRating::where('customer_id', $customer->id)
            ->where('product_id', $request->product_id)
            ->exists();

        if ($alreadyRated) {
            return response()->json(['message' => 'You have already rated this product'], 409);
        }

        $rating = ProductRating::create([
            'customer_id' => $customer->id,
            'product_id' => $request->product_id,
            // `rating` is the headline score the product card reads;
            // `overall_rating` is the same number inside the breakdown.
            'rating' => $request->overall_rating,
            'overall_rating' => $request->overall_rating,
            'quality_rating' => $request->quality_rating,
            'value_rating' => $request->value_rating,
            'price_rating' => $request->price_rating,
        ]);

        return response()->json(['message' => 'Rating submitted successfully', 'rating' => $rating], 201);
    }

    public function calculateAverageRating($productId)
    {
        $ratings = ProductRating::where('product_id', $productId)->get();

        $averageRatings = [
            'overall' => $ratings->avg('overall_rating'),
            'quality' => $ratings->avg('quality_rating'),
            'value' => $ratings->avg('value_rating'),
            'price' => $ratings->avg('price_rating'),
        ];

        // Composite score = mean of the available category averages (null when no ratings).
        $present = array_filter($averageRatings, fn ($v) => ! is_null($v));
        $overallAverage = count($present) ? round(array_sum($present) / count($present), 2) : null;

        return response()->json([
            'averageRatings' => $averageRatings,
            'overallAverage' => $overallAverage,
        ]);
    }
}
