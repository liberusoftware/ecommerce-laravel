<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;
    use IsStoreScoped;
    use IsTenantModel;

    protected $fillable = [
        'code',
        'type',
        'value',
        'valid_from',
        'valid_until',
        'max_uses',
        'min_purchase_amount',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'value' => 'float',
        'max_uses' => 'integer',
        'min_purchase_amount' => 'float',
    ];

    public function orders()
    {
        // Orders link to coupons by code, not coupon_id (see orders.coupon_code column).
        //
        // Codes are unique per store rather than per installation, so the code
        // alone no longer names one coupon. Without the store this counts
        // another merchant's orders, and `max_uses` — which is derived from
        // this count — gets spent by somebody else's customers. (Which also
        // means this relation must not be eager-loaded across coupons of
        // different stores: one instance's store would filter them all.
        // Nothing does today.)
        return $this->hasMany(Order::class, 'coupon_code', 'code')
            ->where('orders.store_id', $this->store_id);
    }

    public function isValid()
    {
        $now = now();

        // Null bounds mean unbounded (no start / no expiry), not "invalid".
        $started = $this->valid_from === null || $this->valid_from <= $now;
        $notExpired = $this->valid_until === null || $this->valid_until >= $now;
        $underLimit = $this->max_uses === null || $this->orders()->count() < $this->max_uses;

        return $started && $notExpired && $underLimit;
    }
}
