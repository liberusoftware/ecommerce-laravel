<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'lifetime_value',
        'average_order_value',
        'total_orders',
        'total_items_purchased',
        'first_purchase_at',
        'last_purchase_at',
        'days_since_last_purchase',
        'predicted_next_order_value',
        'predicted_next_order_date',
        'customer_segment',
        'retention_score',
    ];

    protected $casts = [
        'lifetime_value' => 'decimal:2',
        'average_order_value' => 'decimal:2',
        'total_orders' => 'integer',
        'total_items_purchased' => 'integer',
        'first_purchase_at' => 'datetime',
        'last_purchase_at' => 'datetime',
        'days_since_last_purchase' => 'integer',
        'predicted_next_order_value' => 'decimal:2',
        'predicted_next_order_date' => 'date',
        'retention_score' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Update metrics from user's orders
     */
    public function recalculate(): void
    {
        $user = $this->user;
        $orders = $user->orders()
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_COMPLETED])
            ->get();

        $this->update([
            'total_orders' => $orders->count(),
            'lifetime_value' => $orders->sum('total_amount'),
            'average_order_value' => $orders->avg('total_amount') ?? 0,
            'total_items_purchased' => $orders->sum(function ($order) {
                return $order->items->sum('quantity');
            }),
            'first_purchase_at' => $orders->min('created_at'),
            'last_purchase_at' => $orders->max('created_at'),
            'days_since_last_purchase' => $orders->max('created_at')
                ? $orders->max('created_at')->diffInDays(now())
                : null,
            'customer_segment' => $this->calculateSegment($orders),
            'retention_score' => $this->calculateRetentionScore($orders),
        ]);
    }

    /**
     * Calculate customer segment based on behavior
     */
    protected function calculateSegment($orders): string
    {
        $orderCount = $orders->count();
        $daysSinceLastOrder = $this->days_since_last_purchase ?? 999;

        if ($orderCount === 0) {
            return 'new';
        }

        if ($this->lifetime_value >= 1000) {
            return 'vip';
        }

        if ($daysSinceLastOrder > 180) {
            return 'churned';
        }

        if ($daysSinceLastOrder > 90) {
            return 'at_risk';
        }

        return 'active';
    }

    /**
     * Calculate retention score (0-100)
     */
    protected function calculateRetentionScore($orders): int
    {
        $score = 50; // Base score

        // Reward frequency
        $score += min(25, $orders->count() * 2);

        // Reward recency
        $daysSince = $this->days_since_last_purchase ?? 999;
        if ($daysSince < 30) {
            $score += 15;
        } elseif ($daysSince < 90) {
            $score += 10;
        } elseif ($daysSince < 180) {
            $score += 5;
        } else {
            $score -= 20;
        }

        // Reward value
        if ($this->lifetime_value > 500) {
            $score += 10;
        }

        return max(0, min(100, $score));
    }
}
