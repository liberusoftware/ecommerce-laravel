<?php

namespace Liberu\Ecommerce\ProductCatalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'image'
    ];

    public function getUrlAttribute(): string
    {
        return asset(Storage::url($this->attributes['image']));
    }
}
