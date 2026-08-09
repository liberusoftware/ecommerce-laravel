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
     * Nullable in practice: a line outlives the product it points at.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
