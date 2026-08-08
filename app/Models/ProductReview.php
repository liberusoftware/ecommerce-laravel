<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductReview extends Model
{
    use HasFactory;
    use IsStoreScoped;

    protected $table = 'product_reviews';

    protected $fillable = [
        'product_id',
        'customer_id',
        'comments',
        'is_verified_purchase',
        'helpful_votes',
        'unhelpful_votes',
    ];

    protected $casts = [
        'is_verified_purchase' => 'boolean',
    ];

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
