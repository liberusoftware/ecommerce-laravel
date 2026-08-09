<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRecommendation extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'recommended_product_id',
        'rule_id',
        'score',
        'reason',
    ];

    protected $casts = [
        'score' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function recommendedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'recommended_product_id');
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(RecommendationRule::class);
    }

    public function scopeTopRecommendations($query, $limit = 10)
    {
        return $query->orderByDesc('score')->limit($limit);
    }
}
