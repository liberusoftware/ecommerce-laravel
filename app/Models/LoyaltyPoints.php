<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class LoyaltyPoints extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'loyalty_program_id',
        'balance',
        'lifetime_earned',
        'lifetime_redeemed',
    ];

    protected $casts = [
        'balance' => 'integer',
        'lifetime_earned' => 'integer',
        'lifetime_redeemed' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyPointTransaction::class);
    }

    /**
     * Add points
     */
    public function addPoints(int $points, string $type, ?string $description = null, ?int $orderId = null): void
    {
        // The counters and the ledger row are one write. Unwrapped, a crash
        // between them leaves a balance with nothing behind it — and since every
        // reader reads the counter, nothing would ever say so.
        DB::transaction(function () use ($points, $type, $description, $orderId) {
            $this->increment('balance', $points);
            $this->increment('lifetime_earned', $points);

            $expiresAt = null;
            if ($this->program->points_expiry_days) {
                $expiresAt = now()->addDays($this->program->points_expiry_days);
            }

            $this->transactions()->create([
                'points' => $points,
                'type' => $type,
                'description' => $description,
                'order_id' => $orderId,
                'expires_at' => $expiresAt,
            ]);
        });
    }

    /**
     * Redeem points
     */
    public function redeemPoints(int $points, ?string $description = null, ?int $orderId = null): bool
    {
        // Check-then-act on a row two callers can hold at once: both read the same
        // balance, both pass, both decrement. The lock makes the read and the
        // write one step, so the second caller reads the first one's result.
        return DB::transaction(function () use ($points, $description, $orderId) {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->balance < $points) {
                return false;
            }

            $locked->decrement('balance', $points);
            $locked->increment('lifetime_redeemed', $points);

            $locked->transactions()->create([
                'points' => -$points,
                'type' => 'redeemed',
                'description' => $description,
                'order_id' => $orderId,
            ]);

            // The caller holds this instance, not the locked one.
            $this->refresh();

            return true;
        });
    }

    /**
     * Expire old points
     */
    public function expirePoints(): void
    {
        // Selected by shape, not by label. `addPoints()` takes `$type` from its
        // caller and writes it unvalidated — the migration's
        // `// earned, redeemed, expired, adjustment, bonus` is a comment, not a
        // constraint — so filtering on `type = 'earned'` retired only the lots
        // whose caller happened to pass that one string. Every other label
        // ('purchase', 'bonus', 'signup') earned points that could never expire,
        // and the liability grew without bound.
        //
        // A lot is a positive movement carrying an expiry date. That is true of
        // everything `addPoints()` writes and of nothing else: redemptions and
        // expiries are negative and carry no `expires_at`.
        $expiredTransactions = $this->transactions()
            ->where('points', '>', 0)
            ->where('expires_at', '<=', now())
            ->where('is_expired', false)
            ->get();

        foreach ($expiredTransactions as $transaction) {
            // One lot, one transaction. Marking the lot expired and taking the
            // points off the balance are the same act; split, a crash between
            // them retires a lot whose points are still spendable.
            DB::transaction(function () use ($transaction) {
                // Only points still sitting in the balance can expire. A lot that was
                // already (partly) spent must not drive the balance negative.
                // ponytail: no per-lot tracking — clamp to the running balance; add FIFO lots only if partial-lot expiry is ever required.
                $expiring = (int) min($transaction->points, max(0, $this->balance));

                $transaction->update(['is_expired' => true]);

                if ($expiring <= 0) {
                    return;
                }

                $this->decrement('balance', $expiring);

                $this->transactions()->create([
                    'points' => -$expiring,
                    'type' => 'expired',
                    'description' => 'Points expired',
                ]);
            });
        }
    }
}
