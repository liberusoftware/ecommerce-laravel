<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\ChannelResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `team_id` is written when a row is created, and left alone when nothing can
 * say whose it is.
 *
 * The migration plan's wave 2 existed because **no application code wrote
 * `team_id`**: the column carried `default(1)`, so every row created by the
 * API, a controller, a seeder or a factory became team 1 without anybody
 * deciding that — and afterwards nothing could tell those rows apart from rows
 * that really were team 1's. The plan answered with a backfill and a quarantine
 * rule, which is the only thing you *can* do once the rows exist.
 *
 * They do not exist. Every database here is built from the migrations, so the
 * correction belongs at the point of writing rather than in a migration that
 * guesses afterwards. The default is gone from the schema and the write is in
 * `IsTenantModel`.
 *
 * Both directions matter. A key that is never written is the bug being fixed; a
 * key written with a guess is the bug being reintroduced under a different name.
 */
class TeamIdIsWrittenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_row_created_on_a_storefront_belongs_to_that_storefronts_team(): void
    {
        $team = Team::factory()->create();
        $store = Store::factory()->create(['team_id' => $team->id]);
        $this->storefront($store, 'shop.example.com');

        $product = $this->onHost('shop.example.com', fn () => Product::factory()->create());

        // Derived from the store rather than asked separately: a store belongs
        // to exactly one team, and two independent answers can disagree — which
        // on a tenant key means a row visible in the panel and not on the
        // storefront that sells it, or the reverse.
        $this->assertSame($team->id, $product->team_id);
        $this->assertSame($store->id, $product->store_id);
    }

    public function test_a_row_created_in_a_panel_belongs_to_the_panel_users_team(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->ownedTeams()->first();

        $this->actingAs($user);

        // No host, so no store — a merchant onboarded before their storefront
        // is configured. The panel is the only thing that can answer, and it is
        // right: the row is theirs.
        $product = Product::factory()->create();

        $this->assertSame($team->id, $product->team_id);
    }

    public function test_a_row_nothing_can_attribute_is_left_unstamped(): void
    {
        // No host, no panel user — a console command, a queued job, a seeder.
        $product = Product::factory()->create();

        // Null, not team 1. An unstamped row is a state an operator can see and
        // fix; a row that quietly claims to be team 1's is not.
        $this->assertNull($product->team_id);
    }

    public function test_an_explicit_team_is_not_overwritten(): void
    {
        $team = Team::factory()->create();
        $store = Store::factory()->create(['team_id' => $team->id]);
        $this->storefront($store, 'shop.example.com');

        $other = Team::factory()->create();

        $product = $this->onHost('shop.example.com', fn () => Product::factory()->create(['team_id' => $other->id]));

        // The hook fills a gap; it does not overrule a caller who has said.
        // Filament's own tenancy hook is one such caller, and in a panel the
        // tenant the user is looking at is the authority.
        $this->assertSame($other->id, $product->team_id);
    }

    public function test_no_tenant_key_carries_a_default(): void
    {
        $columns = ['products' => 'team_id', 'orders' => 'team_id', 'customers' => 'team_id'];

        foreach ($columns as $table => $column) {
            // A default on a tenant key turns "nobody said" into a merchant's
            // id. That is the mechanism wave 2 was written to unpick, and it is
            // cheaper to assert its absence than to unpick it twice.
            $this->assertNull(
                $this->defaultFor($table, $column),
                "{$table}.{$column} has a default. A tenant key must not have one.",
            );
        }
    }

    private function storefront(Store $store, string $host): void
    {
        $channel = Channel::factory()->create(['store_id' => $store->id]);
        ChannelDomain::factory()->primary()->create(['channel_id' => $channel->id, 'host' => $host]);
    }

    /**
     * Run a callback as though the request had arrived on a host.
     *
     * Through the resolver and the request attribute the middleware uses, so
     * this exercises the path a real request takes rather than a stub of it.
     */
    private function onHost(string $host, callable $callback): mixed
    {
        $channel = app(ChannelResolver::class)->resolve($host);

        $this->assertNotNull($channel, "No channel resolves {$host} — the fixture is wrong.");

        request()->attributes->set(ChannelResolver::ATTRIBUTE, $channel);

        try {
            return $callback();
        } finally {
            request()->attributes->remove(ChannelResolver::ATTRIBUTE);
        }
    }

    private function defaultFor(string $table, string $column): ?string
    {
        foreach (DB::select('PRAGMA table_info('.$table.')') as $info) {
            if ($info->name === $column) {
                return $info->dflt_value;
            }
        }

        $this->fail("{$table}.{$column} does not exist.");
    }
}
