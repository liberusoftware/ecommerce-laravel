<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The surviving review model — [ADR 0008](../../docs/adr/0008-reviews-and-ratings-merge.md).
 *
 * It won the merge against `Review` because it is keyed to a `Customer` rather
 * than a `User` (a review is written by the person who shops, and one person
 * may shop at several merchants), and because it is store-scoped. `approved`
 * came across from the retired stack: without it the public listing would be
 * unmoderated, which is the one thing the ADR says must not be lost.
 */
class ProductReview extends Model
{
    use HasFactory;
    use IsStoreScoped;

    protected $table = 'product_reviews';

    protected $fillable = [
        'product_id',
        'customer_id',
        'comments',
        'approved',
        'is_verified_purchase',
        'helpful_votes',
        'unhelpful_votes',
    ];

    protected $casts = [
        'approved' => 'boolean',
        'is_verified_purchase' => 'boolean',
    ];

    /** Published reviews only — what a shopper is allowed to see. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approved', true);
    }

    public function approve(): void
    {
        $this->approved = true;
        $this->save();
    }

    public function reject(): void
    {
        $this->approved = false;
        $this->save();
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function isVerifiedPurchase()
    {
        return $this->is_verified_purchase;
    }

    public function getHelpfulnessScore()
    {
        $total_votes = $this->helpful_votes + $this->unhelpful_votes;

        return $total_votes > 0 ? ($this->helpful_votes / $total_votes) * 100 : 0;
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
