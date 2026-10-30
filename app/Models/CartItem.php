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
        'product_variant_id',
        'configuration_key',
        'options',
        'quantity',
        'price',
    ];

    protected $casts = [
        'options' => 'array',
    ];

    /**
     * Nullable in practice: a line outlives the product it points at.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** Trashed included: a removed variant still names the line, and checkout refuses it. */
    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    /** @return list<int> */
    public function choiceIds(): array
    {
        return array_map('intval', array_column($this->options ?? [], 'choice_id'));
    }
}
