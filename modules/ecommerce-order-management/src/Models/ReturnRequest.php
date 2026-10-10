<?php

namespace Liberu\Ecommerce\OrderManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReturnRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'customer_id',
        'rma_number',
        'reason',
        'description',
        'status',
        'return_method',
        'tracking_number',
        'approved_by',
        'approved_at',
        'received_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($returnRequest) {
            if (! $returnRequest->rma_number) {
                $returnRequest->rma_number = 'RMA-'.strtoupper(uniqid());
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnRequestItem::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Approve the return request and spawn a refund for the returned items.
     *
     * Idempotent: re-approving is a no-op (returns null) so the customer is
     * never refunded twice. Returns the processed Refund, or null when there is
     * nothing itemized to refund.
     */
    public function approve(?int $userId = null): ?Refund
    {
        if ($this->status === 'approved') {
            return null;
        }

        $this->update([
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        return $this->spawnRefund($userId);
    }

    /**
     * Build a Refund (+ items) from the returned lines and run it through the
     * refund engine (gateway void → restock → order state transition).
     */
    private function spawnRefund(?int $userId): ?Refund
    {
        $lines = $this->items()->with('orderItem')->get()
            ->filter(fn (ReturnRequestItem $item) => $item->orderItem !== null);

        if ($lines->isEmpty()) {
            return null;
        }

        $refund = Refund::create([
            'order_id' => $this->order_id,
            'amount' => $lines->sum(fn (ReturnRequestItem $item) => (float) $item->orderItem->price * $item->quantity),
            'reason' => $this->reason,
            'status' => 'pending',
            'refund_method' => 'original_payment',
            'restock_items' => true,
        ]);

        foreach ($lines as $item) {
            $refund->items()->create([
                'order_item_id' => $item->order_item_id,
                'quantity' => $item->quantity,
                'amount' => (float) $item->orderItem->price * $item->quantity,
                // ponytail: damaged goods can't be resold — everything else restocks.
                'restock' => $item->condition !== 'damaged',
            ]);
        }

        $refund->process($userId);

        return $refund;
    }

    /**
     * Mark as received
     */
    public function markAsReceived(): void
    {
        $this->update([
            'status' => 'received',
            'received_at' => now(),
        ]);
    }
}
