<?php

namespace Liberu\Ecommerce\Payment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A PayPal subscription owned by a local user. Created (APPROVAL_PENDING) when the
 * user starts a subscription; its status is thereafter driven by PayPal webhooks
 * (ACTIVE / SUSPENDED / CANCELLED / EXPIRED).
 */
class PaypalSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'paypal_subscription_id',
        'plan_id',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
