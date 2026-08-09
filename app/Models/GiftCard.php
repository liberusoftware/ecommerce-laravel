<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class GiftCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'initial_value',
        'balance',
        'currency',
        'customer_id',
        'order_id',
        'expires_at',
        'disabled_at',
        'note',
        'template_suffix',
        'last_characters',
    ];

    protected $casts = [
        'initial_value' => 'decimal:2',
        'balance' => 'decimal:2',
        'expires_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(GiftCardTransaction::class);
    }

    public function isActive(): bool
    {
        return $this->disabled_at === null && 
               ($this->expires_at === null || $this->expires_at->isFuture()) &&
               $this->balance > 0;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function canUse(float $amount): bool
    {
        return $this->isActive() && $this->balance >= $amount;
    }

    public function use(float $amount, Order $order, string $note = null): bool
    {
        if (!$this->canUse($amount)) {
            return false;
        }

        $this->balance -= $amount;
        $this->save();

        $this->transactions()->create([
            'amount' => -$amount,
            'order_id' => $order->id,
            'note' => $note ?? "Used for order #{$order->id}",
        ]);

        return true;
    }

    public function refund(float $amount, Order $order = null, string $note = null): void
    {
        $this->balance += $amount;
        $this->save();

        $this->transactions()->create([
            'amount' => $amount,
            'order_id' => $order?->id,
            'note' => $note ?? 'Refund',
        ]);
    }

    public function disable(string $reason = null): void
    {
        $this->disabled_at = now();
        $this->note = $reason;
        $this->save();
    }

    public function enable(): void
    {
        $this->disabled_at = null;
        $this->save();
    }

    protected static function booted(): void
    {
        static::creating(function ($giftCard) {
            if (empty($giftCard->code)) {
                $giftCard->code = static::generateUniqueCode();
            }
            $giftCard->last_characters = substr($giftCard->code, -4);
        });
    }

    public static function generateUniqueCode(): string
    {
        do {
            $code = strtoupper(Str::random(16));
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public function getMaskedCodeAttribute(): string
    {
        return '****-****-****-' . $this->last_characters;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('disabled_at')
                    ->where(function ($q) {
                        $q->whereNull('expires_at')
                          ->orWhere('expires_at', '>', now());
                    })
                    ->where('balance', '>', 0);
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }
}