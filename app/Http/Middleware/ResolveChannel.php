<?php

namespace App\Http\Middleware;

use App\Models\Channel;
use App\Services\ChannelResolver;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carries the resolved channel on the request, for the storefront and the API.
 *
 * An unresolved host is a 404, with no default-merchant fallback. That rule is
 * half of the control: the scope narrows a resolved host to its own store, and
 * this refuses a host that resolves to none — otherwise an unconfigured
 * hostname is an unscoped one, which is the leak with extra steps.
 *
 * Single-merchant deployments and local development configure their one
 * channel's domain, `localhost` included; the initial-channel migration does it
 * for them. *A configured fallback is exactly how `default(1)` produced the mess
 * wave 2 is unpicking.*
 *
 * Panels are not in this stack. They resolve a Team through Filament tenancy and
 * list their own middleware rather than using the `web` group.
 */
class ResolveChannel
{
    public function __construct(private readonly ChannelResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $channel = $this->resolve($request->getHost());

        $request->attributes->set(ChannelResolver::ATTRIBUTE, $channel);

        // The one exemption. `/health` is a Kubernetes liveness and readiness
        // probe, and probes arrive on the pod's own address rather than on any
        // configured hostname. 404ing it would restart healthy pods, and it
        // reads no tenant data — it reports whether the database answers.
        if ($channel === null && ! $request->is('health')) {
            abort(404);
        }

        return $next($request);
    }

    /**
     * An unreachable or unmigrated database is an unresolved host, not a 500.
     *
     * This sits in front of every storefront route including `/health`, which
     * exists precisely to answer while the database is down — it reports
     * `degraded` rather than failing to respond at all. A middleware that throws
     * first would take that away, and would also break the window between
     * deploying and running migrations.
     */
    private function resolve(string $host): ?Channel
    {
        try {
            return $this->resolver->resolve($host);
        } catch (QueryException) {
            return null;
        }
    }
}
