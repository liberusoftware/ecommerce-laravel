<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'sku',
        'variant_attributes',
        'options',
        'quantity',
        'price',
        'download_link',
        'download_expires_at',
        'download_count',
    ];

    protected $casts = [
        'download_expires_at' => 'datetime',
        'options' => 'array',
        'variant_attributes' => 'array',
    ];

    /** The name the line was sold under; the live product only for lines older than the snapshot. */
    public function displayName(): string
    {
        return $this->product_name ?? $this->product?->name ?? 'Product #'.$this->product_id;
    }

    /** "Colour: Black, Storage: 128 GB" as sold; null for a simple product. */
    public function variantLabel(): ?string
    {
        return self::variantLabelFor($this->variant_attributes);
    }

    /** Shared with the cart. */
    public static function variantLabelFor(?array $values): ?string
    {
        if (empty($values)) {
            return null;
        }

        return implode(', ', array_map(fn (array $v) => $v['name'].': '.$v['value'], $values));
    }

    /** @return list<string> e.g. "Size: Large (+£0.20)" */
    public function optionLabels(?string $currency = null): array
    {
        return self::labelsFor($this->options ?? [], $currency);
    }

    /** Shared with the cart, whose lines carry the same snapshot shape. */
    public static function labelsFor(array $options, ?string $currency = null): array
    {
        return array_map(function (array $option) use ($currency): string {
            $adjustment = (float) $option['price_adjustment'];
            $price = $adjustment == 0.0 ? '' : ' ('.($adjustment > 0 ? '+' : '−').Money::format(abs($adjustment), $currency).')';

            return $option['group'].': '.$option['name'].$price;
        }, $options);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Check if the download link is still valid
     */
    public function isDownloadValid(): bool
    {
        if (! $this->download_link || ! $this->download_expires_at) {
            return false;
        }

        return $this->download_expires_at->isFuture();
    }
}
