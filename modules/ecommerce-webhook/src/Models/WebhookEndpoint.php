<?php

namespace Liberu\Ecommerce\Webhook\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEndpoint extends Model
{
    use HasFactory;

    protected $fillable = ['url', 'secret', 'events', 'is_active'];

    /** The signing secret is returned once at creation (see the controller's store) and never serialised again. */
    protected $hidden = ['secret'];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
    ];

    /** Does this endpoint subscribe to the given event name? */
    public function subscribesTo(string $event): bool
    {
        return in_array($event, (array) $this->events, true);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
