<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductRating extends Model
{
    use HasFactory;
    use IsStoreScoped;

    protected $table = 'product_rating';

    protected $fillable = [
        'product_id',
        'customer_id',
        // The headline score. `Product::getAverageRating()` and the product card
        // read this column, and it is NOT NULL — so it is written on every
        // rating, with `overall_rating` and the breakdown beside it.
        'rating',
        'overall_rating',
        'quality_rating',
        'value_rating',
        'price_rating',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function getAverageRating()
    {
        return ($this->overall_rating + $this->quality_rating + $this->value_rating + $this->price_rating) / 4;
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
