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
 * It does not yet refuse an unresolved host. That rule — an unresolved host is
 * a 404, with no default-merchant fallback — belongs with the tenant scope it
 * protects: 404ing before there is a scope guards nothing, and would take the
 * storefront down in every environment whose hostname is not configured yet.
 * The order is deliberate, and it is the same one wave 2 uses: get the data in
 * place first, then turn on the control that reads it.
 *
 * Panels are not in this stack. They resolve a Team through Filament tenancy and
 * list their own middleware rather than using the `web` group.
 */
class ResolveChannel
{
    public function __construct(private readonly ChannelResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(
            ChannelResolver::ATTRIBUTE,
            $this->resolve($request->getHost()),
        );

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
