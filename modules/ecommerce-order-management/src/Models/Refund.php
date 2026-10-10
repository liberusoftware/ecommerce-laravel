<?php

namespace Liberu\Ecommerce\OrderManagement\Models;

use Liberu\Ecommerce\OrderManagement\Notifications\OrderRefundedNotification;
use Liberu\Ecommerce\OrderManagement\Services\PaymentGatewayService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Notification;

class Refund extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'amount',
        'reason',
        'notes',
        'status',
        'refund_method',
        'transaction_id',
        'processed_by',
        'processed_at',
        'restock_items',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'processed_at' => 'datetime',
        'restock_items' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Process the refund
     */
    public function process(?int $userId = null): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        $order = $this->order;

        // Void the payment with the gateway FIRST. If it has a charge to refund and
        // the gateway declines, nothing changes — no restock, no state move.
        $result = [];
        if ($order->transaction_id) {
            $result = app(PaymentGatewayService::class)->refundPayment(
                $order->payment_method,
                $order->transaction_id,
                (float) $this->amount,
            );

            if (! ($result['success'] ?? false)) {
                return false;
            }
        }

        $this->update([
            'status' => 'processed',
            'processed_by' => $userId,
            'processed_at' => now(),
            'transaction_id' => $result['refund_id'] ?? $this->transaction_id,
        ]);

        // Restock refunded items if requested.
        if ($this->restock_items) {
            foreach ($this->items as $item) {
                if ($item->restock && $item->orderItem && $item->orderItem->product) {
                    $item->orderItem->product->increment('inventory_count', $item->quantity);
                }
            }
        }

        // Update refund totals + move the order through the state machine.
        $order->increment('refund_total', $this->amount);
        $order->refresh();

        $fully = (float) $order->refund_total >= (float) $order->total_amount;
        $order->update([
            'partially_refunded' => ! $fully,
            'fully_refunded' => $fully,
        ]);

        $target = $fully ? Order::STATUS_REFUNDED : Order::STATUS_PARTIALLY_REFUNDED;
        if ($order->status !== $target && in_array($target, Order::TRANSITIONS[$order->status] ?? [], true)) {
            $order->transitionTo($target, $userId, 'Refund processed');
        }

        $this->notifyCustomer($order);

        return true;
    }

    /**
     * Tell the customer the refund went through. Logged-in customers get the
     * mail + database (bell) notification; guests get an on-demand email.
     */
    private function notifyCustomer(Order $order): void
    {
        $notification = new OrderRefundedNotification($order, (float) $this->amount);

        if ($order->user_id && $user = User::find($order->user_id)) {
            $user->notify($notification);
        } elseif ($order->customer_email) {
            Notification::route('mail', $order->customer_email)->notify($notification);
        }
    }
}
