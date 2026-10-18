<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The sync state of one Product inside a Team's Meta Catalog.
 * `status` and `errors` mirror what Meta owns; the application owns the content.
 *
 * No `team_id`: the owner is the Product's.
 */
class ProductFacebookListing extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
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
}
