<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsEvent extends Model
{
    use HasFactory;

    const EVENT_PAGE_VIEW = 'page_view';

    const EVENT_PRODUCT_VIEW = 'product_view';

    const EVENT_ADD_TO_CART = 'add_to_cart';

    const EVENT_REMOVE_FROM_CART = 'remove_from_cart';

    const EVENT_CHECKOUT_START = 'checkout_start';

    const EVENT_PURCHASE = 'purchase';

    const EVENT_SEARCH = 'search';

    const EVENT_WISHLIST_ADD = 'wishlist_add';

    const EVENT_EMAIL_SIGNUP = 'email_signup';

    protected $fillable = [
        'event_type',
        'customer_id',
        'session_id',
        'product_id',
        'order_id',
        'page_url',
        'referrer_url',
        'user_agent',
        'ip_address',
        'country',
        'city',
        'device_type',
        'browser',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'event_data',
        'revenue',
        'quantity',
        'search_term',
    ];

    protected $casts = [
        'event_data' => 'array',
        'revenue' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scopeByEventType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeByCustomer($query, Customer $customer)
    {
        return $query->where('customer_id', $customer->id);
    }

    public function scopeBySession($query, string $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    public function scopeWithRevenue($query)
    {
        return $query->whereNotNull('revenue')->where('revenue', '>', 0);
    }

    public static function trackPageView(string $url, array $data = []): self
    {
        return static::create(array_merge([
            'event_type' => static::EVENT_PAGE_VIEW,
            'page_url' => $url,
            'session_id' => session()->getId(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'referrer_url' => request()->header('referer'),
        ], $data));
    }

    public static function trackProductView(Product $product, array $data = []): self
    {
        return static::create(array_merge([
            'event_type' => static::EVENT_PRODUCT_VIEW,
            'product_id' => $product->id,
            'session_id' => session()->getId(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'page_url' => request()->url(),
        ], $data));
    }

    public static function trackAddToCart(Product $product, int $quantity = 1, array $data = []): self
    {
        return static::create(array_merge([
            'event_type' => static::EVENT_ADD_TO_CART,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'session_id' => session()->getId(),
            'ip_address' => request()->ip(),
            'revenue' => $product->price * $quantity,
        ], $data));
    }

    public static function trackPurchase(Order $order, array $data = []): self
    {
        return static::create(array_merge([
            'event_type' => static::EVENT_PURCHASE,
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'revenue' => $order->total_amount,
            'session_id' => session()->getId(),
            'ip_address' => request()->ip(),
        ], $data));
    }

    public static function trackSearch(string $searchTerm, int $resultsCount = 0, array $data = []): self
    {
        return static::create(array_merge([
            'event_type' => static::EVENT_SEARCH,
            'search_term' => $searchTerm,
            'session_id' => session()->getId(),
            'ip_address' => request()->ip(),
            'event_data' => ['results_count' => $resultsCount],
        ], $data));
    }
}
