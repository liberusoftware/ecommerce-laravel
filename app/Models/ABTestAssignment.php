<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ABTestAssignment extends Model
{
    use HasFactory;

    protected $table = 'ab_test_assignments';

    public $timestamps = false;

    protected $fillable = [
        'test_id',
        'user_id',
        'session_id',
        'variant_name',
        'assigned_at',
        'converted',
        'converted_at',
        'conversion_value',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'converted' => 'boolean',
        'converted_at' => 'datetime',
        'conversion_value' => 'decimal:2',
    ];

    public function test(): BelongsTo
    {
        return $this->belongsTo(ABTest::class, 'test_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mark as converted
     */
    public function markConverted(?float $value = null): void
    {
        $this->update([
            'converted' => true,
            'converted_at' => now(),
            'conversion_value' => $value,
        ]);
    }
}
