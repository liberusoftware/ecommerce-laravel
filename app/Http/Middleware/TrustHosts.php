<?php

namespace App\Http\Middleware;

use App\Models\ChannelDomain;
use Illuminate\Database\QueryException;
use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Which hostnames this deployment answers on.
 *
 * Tenancy is resolved from the `Host` header, which is what makes the header
 * security-relevant here rather than merely untidy: it selects the store, and
 * every absolute URL the application generates from it — password resets,
 * canonicals, webhook callbacks — carries whatever it said.
 *
 * The list is the channel domains, which is the same table `ChannelResolver`
 * reads. A second hand-maintained list of the same hostnames drifts, and both
 * directions of drift are outages: a live storefront answering 400, or a host
 * trusted that resolves to nothing.
 */
class TrustHosts extends Middleware
{
    /**
     * Cleared by `ChannelDomain` on write. Public because the model that
     * invalidates it should name the thing it invalidates.
     */
    public const CACHE_KEY = 'trusted-hosts';

    /**
     * `/health` is exempt, for the same reason it is exempt from the
     * unresolved-host 404: a Kubernetes probe arrives on the pod's own address
     * rather than on any configured hostname, and refusing it restarts healthy
     * pods. It generates no URLs and reads no tenant data, so there is nothing
     * for a forged header to reach.
     *
     * `$next` is untyped to match the parent, which declares it untyped.
     * Narrowing it to `Closure` is a fatal at class load, not a warning at the
     * call — and a global middleware that cannot be loaded takes down every
     * request there is.
     */
    public function handle(Request $request, $next)
    {
        if ($request->is('health')) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    /**
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        return array_merge(
            // Kept alongside the channel domains rather than replaced by them.
            // It is what answers in the window between deploying and running
            // migrations, and on a deployment whose panel sits on a hostname no
            // storefront resolves. It widens the trusted set without widening
            // what resolves: `ResolveChannel` still 404s a host that belongs to
            // no channel.
            [$this->allSubdomainsOfApplicationUrl()],
            $this->channelDomainPatterns(),
        );
    }

    /**
     * Every configured hostname, as an anchored literal.
     *
     * Symfony matches trusted hosts as patterns, so the hostname is quoted
     * before it is anchored — an unescaped `.` matches any character, and
     * `shop.example.com` would then trust `shopxexample.com`, which somebody
     * can register.
     *
     * @return array<int, string>
     */
    private function channelDomainPatterns(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => ChannelDomain::query()
                ->orderBy('host')
                ->pluck('host')
                ->map(fn (string $host) => '^'.preg_quote($host).'$')
                ->all());
        } catch (QueryException) {
            // An unreachable or unmigrated database trusts what it did before
            // there was a table to read, rather than trusting nothing. This
            // runs in front of every request, and the alternative is a
            // deployment that cannot serve the page that says it is broken.
            return [];
        }
    }
}
