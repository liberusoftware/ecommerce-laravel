<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    use HasFactory;
    use IsStoreScoped;

    protected $table = 'cart_items';

    protected $fillable = [
        // Exactly one of these two identifies the cart. `CartService::owner()`
        // is what decides which, and it is the only thing that should.
        'user_id',
        'guest_token',
        'product_id',
        'quantity',
        'price',
    ];

    /**
     * The product on this line.
     *
     * The foreign key is named because Eloquent derives it from the *method*,
     * and this method is plural: `products` gave `products_id`, a column that
     * does not exist. Nothing errored — a missing key attribute reads as null,
     * so eager loading quietly returned no product at all, and every caller
     * treated that as "this line has no product": the API returned a cart with
     * null products, the GraphQL cart resolved `product: null`, and the
     * headless checkout skipped every line when calculating tax.
     *
     * The name stays plural because four call sites say `products`, and a
     * relation that works under a bad name beats a rename in the middle of a
     * merge. Worth correcting on its own.
     */
    public function products()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
