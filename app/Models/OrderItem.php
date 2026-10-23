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
        'product_name',
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
    ];

    /** The name the line was sold under; the live product only for lines older than the snapshot. */
    public function displayName(): string
    {
        return $this->product_name ?? $this->product?->name ?? 'Product #'.$this->product_id;
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
