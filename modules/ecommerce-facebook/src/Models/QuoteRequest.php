<?php

namespace Liberu\Ecommerce\Facebook\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuoteRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'quote_number',
        'status',
        'items',
        'notes',
        'response_notes',
        'quoted_total',
        'valid_until',
        'responded_by',
        'responded_at',
    ];

    protected $casts = [
        'items' => 'array',
        'quoted_total' => 'decimal:2',
        'valid_until' => 'datetime',
        'responded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($quote) {
            if (!$quote->quote_number) {
                $quote->quote_number = 'QT-' . strtoupper(uniqid());
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by');
    }

    /**
     * Check if quote is still valid
     */
    public function isValid(): bool
    {
        if ($this->status !== 'sent') {
            return false;
        }

        if ($this->valid_until && now()->gt($this->valid_until)) {
            return false;
        }

        return true;
    }

    /**
     * Accept the quote
     */
    public function accept(): void
    {
        $this->update(['status' => 'accepted']);
    }

    /**
     * Reject the quote
     */
    public function reject(): void
    {
        $this->update(['status' => 'rejected']);
    }
}
