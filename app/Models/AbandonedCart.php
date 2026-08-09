<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AbandonedCart extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'customer_email',
        'session_id',
        'cart_token',
        'total_amount',
        'currency',
        'abandoned_at',
        'recovered_at',
        'recovery_email_sent_at',
        'recovery_email_count',
        'checkout_url',
        'line_items',
        'customer_locale',
        'billing_address',
        'shipping_address',
        'recovery_order_id',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'abandoned_at' => 'datetime',
        'recovered_at' => 'datetime',
        'recovery_email_sent_at' => 'datetime',
        'recovery_email_count' => 'integer',
        'line_items' => 'array',
        'billing_address' => 'array',
        'shipping_address' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function recoveryEmails(): HasMany
    {
        return $this->hasMany(CartRecoveryAttempt::class);
    }

    public function isRecovered(): bool
    {
        return $this->recovered_at !== null;
    }

    public function canSendRecoveryEmail(): bool
    {
        if ($this->isRecovered()) {
            return false;
        }

        // Don't send if last email was sent less than 1 hour ago
        if ($this->recovery_email_sent_at && $this->recovery_email_sent_at->diffInHours(now()) < 1) {
            return false;
        }

        // Don't send more than 3 recovery emails
        return $this->recovery_email_count < 3;
    }

    public function markAsRecovered(?Order $order = null): void
    {
        $this->recovered_at = now();
        if ($order) {
            $this->recovery_order_id = $order->id;
        }
        $this->save();
    }

    public function incrementEmailCount(): void
    {
        $this->recovery_email_sent_at = now();
        $this->recovery_email_count++;
        $this->save();
    }

    public function getRecoveryUrlAttribute(): string
    {
        return route('cart.recover', ['token' => $this->cart_token]);
    }

    public function getTotalItemsAttribute(): int
    {
        return collect($this->line_items)->sum('quantity');
    }

    public function scopeNotRecovered($query)
    {
        return $query->whereNull('recovered_at');
    }

    public function scopeOlderThan($query, $hours)
    {
        return $query->where('abandoned_at', '<', now()->subHours($hours));
    }

    public function scopeCanSendEmail($query)
    {
        return $query->notRecovered()
                    ->where('recovery_email_count', '<', 3)
                    ->where(function ($q) {
                        $q->whereNull('recovery_email_sent_at')
                          ->orWhere('recovery_email_sent_at', '<', now()->subHour());
                    });
    }
}