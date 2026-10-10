<?php

namespace Liberu\Ecommerce\OrderManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'user_id',
        'note',
        'type',
        'customer_visible',
        'is_system_note',
    ];

    protected $casts = [
        'customer_visible' => 'boolean',
        'is_system_note' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create a customer note
     */
    public static function createCustomerNote(int $orderId, string $note, ?int $userId = null): self
    {
        return static::create([
            'order_id' => $orderId,
            'user_id' => $userId,
            'note' => $note,
            'type' => 'customer',
            'customer_visible' => true,
        ]);
    }

    /**
     * Create an internal note
     */
    public static function createInternalNote(int $orderId, string $note, ?int $userId = null): self
    {
        return static::create([
            'order_id' => $orderId,
            'user_id' => $userId,
            'note' => $note,
            'type' => 'internal',
            'customer_visible' => false,
        ]);
    }

    /**
     * Create a system note
     */
    public static function createSystemNote(int $orderId, string $note): self
    {
        return static::create([
            'order_id' => $orderId,
            'note' => $note,
            'type' => 'system',
            'customer_visible' => false,
            'is_system_note' => true,
        ]);
    }
}
