<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPerformance extends Model
{
    use HasFactory;

    // Migration creates `product_performance`; without this, Eloquent would
    // pluralise to the non-existent `product_performances` and every query fatals.
    protected $table = 'product_performance';

    protected $fillable = [
        'product_id',
        'date',
        'views',
        'add_to_cart',
        'purchases',
        'revenue',
        'conversion_rate',
        'returns',
        'return_rate',
    ];

    protected $casts = [
        // No date cast: the record* helpers store/query the date as a plain
        // 'Y-m-d' string. A Carbon cast serialises to 'Y-m-d 00:00:00', which
        // updateOrCreate's raw lookup then never matches -> duplicate INSERT
        // and a unique(product_id, date) violation on the second call of a day.
        'views' => 'integer',
        'add_to_cart' => 'integer',
        'purchases' => 'integer',
        'revenue' => 'decimal:2',
        'conversion_rate' => 'decimal:2',
        'returns' => 'integer',
        'return_rate' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Update conversion rate based on views and purchases
     */
    public function calculateConversionRate(): void
    {
        if ($this->views > 0) {
            $this->conversion_rate = round(($this->purchases / $this->views) * 100, 2);
        } else {
            $this->conversion_rate = 0;
        }

        $this->save();
    }

    /**
     * Update return rate
     */
    public function calculateReturnRate(): void
    {
        if ($this->purchases > 0) {
            $this->return_rate = round(($this->returns / $this->purchases) * 100, 2);
        } else {
            $this->return_rate = 0;
        }

        $this->save();
    }

    /**
     * Record a product view
     */
    public static function recordView(int $productId, ?string $date = null): void
    {
        $date = $date ?? now()->toDateString();

        static::updateOrCreate(
            ['product_id' => $productId, 'date' => $date],
            []
        )->increment('views');
    }

    /**
     * Record add to cart
     */
    public static function recordAddToCart(int $productId, ?string $date = null): void
    {
        $date = $date ?? now()->toDateString();

        static::updateOrCreate(
            ['product_id' => $productId, 'date' => $date],
            []
        )->increment('add_to_cart');
    }

    /**
     * Record purchase
     */
    public static function recordPurchase(int $productId, float $revenue, ?string $date = null): void
    {
        $date = $date ?? now()->toDateString();

        $performance = static::updateOrCreate(
            ['product_id' => $productId, 'date' => $date],
            []
        );

        $performance->increment('purchases');
        $performance->increment('revenue', $revenue);
        $performance->calculateConversionRate();
    }
}
