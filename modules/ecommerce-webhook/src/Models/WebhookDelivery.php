<?php

namespace Liberu\Ecommerce\Webhook\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    protected $fillable = [
        'webhook_endpoint_id',
        'order_id',
        'event',
        'status_code',
        'success',
        'attempt',
        'error',
    ];

    protected $casts = [
        'success' => 'boolean',
        'status_code' => 'integer',
        'attempt' => 'integer',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
