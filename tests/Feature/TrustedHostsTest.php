<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustHosts;
use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Wave 1.5, step 1: the trusted-host list is the channel domains.
 *
 * Tenancy is resolved from the `Host` header, which is what makes the header
 * security-relevant rather than merely untidy — it selects the store, and every
 * absolute URL generated from it carries whatever it said. A second
 * hand-maintained list of the same hostnames drifts, and both directions of
 * drift are outages: a live storefront answering 400, or a host trusted that
 * resolves to nothing.
 *
 * Laravel only *applies* trusted hosts outside local and tests, so what is
 * exercised here is the list itself and the cache in front of it.
 */
class TrustedHostsTest extends TestCase
{
    use RefreshDatabase;

    private function domain(string $host): ChannelDomain
    {
        $store = Store::factory()->create();
        $channel = Channel::factory()->create(['store_id' => $store->id]);

        return ChannelDomain::factory()->create(['channel_id' => $channel->id, 'host' => $host]);
    }

    /**
     * @return array<int, string>
     */
    private function patterns(): array
    {
        return array_values(array_filter(app(TrustHosts::class)->hosts()));
    }

    private function isTrusted(string $host): bool
    {
        foreach ($this->patterns() as $pattern) {
            if (preg_match('{'.$pattern.'}i', $host) === 1) {
                return true;
            }
        }

        return false;
    }

    public function test_a_configured_channel_domain_is_trusted(): void
    {
        $this->domain('shop.example.com');

        $this->assertTrue($this->isTrusted('shop.example.com'));
    }

    public function test_a_hostname_nobody_configured_is_not_trusted(): void
    {
        $this->domain('shop.example.com');

        $this->assertFalse($this->isTrusted('attacker.example.net'));
    }

    /**
     * The dot is the whole reason these are quoted. Unescaped it matches any
     * character, so `shop.example.com` would trust `shopxexample.com` — a
     * hostname somebody can register, pointed at this deployment, generating
     * password-reset links that leave with it.
     */
    public function test_a_dot_in_a_hostname_is_not_a_wildcard(): void
    {
        $this->domain('shop.example.com');

        $this->assertFalse($this->isTrusted('shopxexample.com'));
    }

    /**
     * Anchored at both ends: a trusted hostname must be the whole host, not a
     * substring of somebody else's.
     */
    public function test_a_trusted_hostname_inside_a_longer_one_is_not_trusted(): void
    {
        $this->domain('shop.example.com');

        $this->assertFalse($this->isTrusted('shop.example.com.attacker.net'));
        $this->assertFalse($this->isTrusted('notshop.example.com'));
    }

    /**
     * The list is cached in front of a table read that would otherwise run on
     * every request. A merchant adding a domain whose storefront then answers
     * 400 until something else clears the cache is the failure this prevents.
     */
    public function test_adding_a_domain_makes_it_trusted_immediately(): void
    {
        $this->domain('first.example.com');

        // Warm the cache with a list that does not contain the new host.
        $this->assertFalse($this->isTrusted('second.example.com'));

        $this->domain('second.example.com');

        $this->assertTrue($this->isTrusted('second.example.com'));
    }

    public function test_removing_a_domain_stops_it_being_trusted(): void
    {
        $domain = $this->domain('gone.example.com');

        $this->assertTrue($this->isTrusted('gone.example.com'));

        $domain->delete();

        $this->assertFalse($this->isTrusted('gone.example.com'));
    }

    /**
     * Renaming is a `saved` rather than a `created`, and the old hostname has
     * to stop being trusted at the same moment the new one starts.
     */
    public function test_renaming_a_domain_moves_the_trust_with_it(): void
    {
        $domain = $this->domain('old.example.com');

        $this->assertTrue($this->isTrusted('old.example.com'));

        $domain->update(['host' => 'new.example.com']);

        $this->assertTrue($this->isTrusted('new.example.com'));
        $this->assertFalse($this->isTrusted('old.example.com'));
    }

    /**
     * The application's own URL stays in the list rather than being replaced by
     * the table. It is what answers in the window between deploying and running
     * migrations, and on a deployment whose panel sits on a hostname no
     * storefront resolves. It widens what is *trusted*, not what *resolves* —
     * `ResolveChannel` still 404s a host belonging to no channel.
     */
    public function test_the_application_url_is_trusted_with_no_domains_configured(): void
    {
        ChannelDomain::query()->delete();
        Cache::forget(TrustHosts::CACHE_KEY);

        $this->assertTrue($this->isTrusted(parse_url(config('app.url'), PHP_URL_HOST)));
    }
}
