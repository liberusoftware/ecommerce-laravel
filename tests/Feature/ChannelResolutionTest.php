<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\ChannelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * host → Channel → Store → Team, the resolution wave 1.5's tenant scope reads.
 *
 * Nothing refuses an unresolved host yet, and nothing scopes on the result yet.
 * Both follow, and both depend on this being right first: a scope keyed off a
 * resolution that picks the wrong channel is worse than no scope at all,
 * because it looks like a control.
 */
class ChannelResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function channelFor(string $host): Channel
    {
        $channel = Channel::factory()->create();
        ChannelDomain::factory()->primary()->create(['channel_id' => $channel->id, 'host' => $host]);

        return $channel;
    }

    public function test_a_host_resolves_to_its_channel(): void
    {
        $expected = $this->channelFor('shop.example.com');
        $this->channelFor('other.example.com');

        $resolved = app(ChannelResolver::class)->resolve('shop.example.com');

        $this->assertNotNull($resolved);
        $this->assertSame($expected->id, $resolved->id);
    }

    /**
     * A visitor typing the hostname in any case, or arriving on a non-default
     * port as local development does, is on the same storefront.
     */
    public function test_a_host_resolves_regardless_of_case_or_port(): void
    {
        $expected = $this->channelFor('shop.example.com');

        foreach (['SHOP.EXAMPLE.COM', 'shop.example.com:8000', ' shop.example.com '] as $host) {
            $this->assertSame(
                $expected->id,
                app(ChannelResolver::class)->resolve($host)?->id,
                "[{$host}] did not resolve to the same channel.",
            );
        }
    }

    public function test_an_unknown_host_resolves_to_nothing(): void
    {
        $this->channelFor('shop.example.com');

        $this->assertNull(app(ChannelResolver::class)->resolve('somebody-elses.example.com'));
    }

    public function test_a_channel_reaches_its_store_and_team(): void
    {
        $team = Team::factory()->create(['user_id' => User::factory()->create()->id]);
        $store = Store::factory()->create(['team_id' => $team->id]);
        $channel = Channel::factory()->create(['store_id' => $store->id]);
        ChannelDomain::factory()->primary()->create(['channel_id' => $channel->id, 'host' => 'shop.example.com']);

        $resolved = app(ChannelResolver::class)->resolve('shop.example.com');

        $this->assertSame($store->id, $resolved->store->id);
        $this->assertSame($team->id, $resolved->store->team->id);
    }

    /**
     * The whole point of `channel_domains` being a table: one storefront answers
     * on the apex, `www`, a custom domain and a platform subdomain, and only one
     * of them is canonical.
     */
    public function test_one_channel_answers_on_several_hosts_with_one_canonical(): void
    {
        $channel = Channel::factory()->create();
        ChannelDomain::factory()->primary()->create(['channel_id' => $channel->id, 'host' => 'example.com']);
        ChannelDomain::factory()->create(['channel_id' => $channel->id, 'host' => 'www.example.com']);

        $resolver = app(ChannelResolver::class);

        $this->assertSame($channel->id, $resolver->resolve('example.com')->id);
        $this->assertSame($channel->id, $resolver->resolve('www.example.com')->id);
        $this->assertSame('example.com', $channel->primaryDomain()->host);
    }

    /**
     * The middleware runs on the storefront, so a request carries its channel
     * without any controller asking for it.
     */
    public function test_a_storefront_request_carries_its_resolved_channel(): void
    {
        // `localhost` is already claimed by the initial-channel migration, which
        // is what the test suite arrives on.
        $this->get('/health')->assertOk();

        $this->assertNotNull(
            ChannelResolver::current(),
            'The storefront request resolved no channel, so nothing downstream can scope on one.',
        );
    }

    public function test_the_initial_migration_leaves_every_environment_resolvable(): void
    {
        $resolver = app(ChannelResolver::class);

        $this->assertNotNull($resolver->resolve('localhost'));
        $this->assertNotNull($resolver->resolve('127.0.0.1'));
    }
}
