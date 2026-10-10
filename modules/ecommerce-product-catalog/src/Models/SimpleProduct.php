<?php

namespace Liberu\Ecommerce\ProductCatalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SimpleProduct extends Model
{
    use HasFactory;

    protected $table = 'simple_product';

    protected $fillable = [
        'quantity',
        'price',
        'product_id',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
