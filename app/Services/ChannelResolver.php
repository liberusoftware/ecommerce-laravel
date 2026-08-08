<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\ChannelDomain;

/**
 * Which channel is `shop.example.com`?
 *
 * The domain question, kept apart from the HTTP one — how a request carries the
 * answer — which lives in `ResolveChannel` middleware.
 */
class ChannelResolver
{
    /**
     * Where the resolved channel rides on the request.
     */
    public const ATTRIBUTE = 'resolved_channel';

    public function resolve(string $host): ?Channel
    {
        $host = ChannelDomain::normalise($host);

        if ($host === '') {
            return null;
        }

        return Channel::whereRelation('domains', 'host', $host)->with('store')->first();
    }

    /**
     * The channel resolved for the current request, or null off a resolved host
     * — a queued job, a console command, a panel route.
     *
     * Read from the request rather than a container binding, so that absence is
     * the natural state rather than something that has to be unbound.
     */
    public static function current(): ?Channel
    {
        return request()->attributes->get(self::ATTRIBUTE);
    }
}
