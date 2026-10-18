<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImage extends Model
{
    use HasFactory;

    protected $fillable = [
        'image',
        'order',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** Served host-relative, like Product::image_url, so it loads on every storefront domain. */
    public function getUrlAttribute(): ?string
    {
        $path = $this->attributes['image'] ?? null;

        if (blank($path)) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://', '//', '/'])) {
            return $path;
        }

        return '/'.ltrim((string) parse_url(Storage::disk('public')->url($path), PHP_URL_PATH), '/');
    }
}
