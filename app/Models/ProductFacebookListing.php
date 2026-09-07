<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The sync state of one Product (or one Variant) inside a Team's Meta Catalog.
 * `status` and `errors` mirror what Meta owns; the application owns the content.
 *
 * No `team_id`: the owner is the Product's, the way ProductVariant's is.
 */
class ProductFacebookListing extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'retailer_id',
        'catalog_item_id',
        'status',
        'errors',
        'last_synced_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'last_synced_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
