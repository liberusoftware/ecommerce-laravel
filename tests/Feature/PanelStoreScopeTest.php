<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave 1.5, step 3, in the panel.
 *
 * Filament scopes panel *resources* by team, which is a real control and the
 * reason this is a refinement rather than the fix. What it does not scope is
 * everything around them — relation managers, widgets, custom pages, and any
 * bare `Model::query()` someone writes in a panel. The store scope is inert on
 * those today, because off a resolved host it had nothing to scope by.
 *
 * It does have something: the team the panel user is working in.
 */
class PanelStoreScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A merchant: a team, a store it owns, and the user working in it.
     *
     * @return array{0: Team, 1: Store, 2: User}
     */
    private function merchant(): array
    {
        $team = Team::factory()->create();
        $store = Store::factory()->create(['team_id' => $team->id]);
        $user = User::factory()->create(['current_team_id' => $team->id]);

        return [$team, $store, $user];
    }

    public function test_a_panel_user_does_not_see_another_teams_rows(): void
    {
        [, $mine, $me] = $this->merchant();
        [, $theirs] = $this->merchant();

        $ours = Product::factory()->create(['store_id' => $mine->id]);
        Product::factory()->create(['store_id' => $theirs->id]);

        $this->actingAs($me);

        $this->assertSame([$ours->id], Product::query()->pluck('id')->all());
    }

    /**
     * A team may own several storefronts, and a merchant in the panel is
     * working across their business rather than one shopfront — Filament gives
     * them no store selector to narrow it with. Scoping to a single store here
     * would hide half their catalogue from them.
     */
    public function test_a_panel_user_sees_every_store_their_team_owns(): void
    {
        [$team, $first, $me] = $this->merchant();
        $second = Store::factory()->create(['team_id' => $team->id]);

        $here = Product::factory()->create(['store_id' => $first->id]);
        $there = Product::factory()->create(['store_id' => $second->id]);

        $this->actingAs($me);

        $this->assertEqualsCanonicalizing(
            [$here->id, $there->id],
            Product::query()->pluck('id')->all(),
        );
    }

    /**
     * The write half. A panel row that lands unstamped is invisible to the
     * storefront that sells it, which is the same defect read-scoping fixes,
     * arriving a day later.
     */
    public function test_a_row_created_in_a_panel_is_stamped_with_the_teams_store(): void
    {
        [, $mine, $me] = $this->merchant();

        // A second team with its own store, so the deployment is not
        // single-store and the shortcut that answers there cannot be what
        // answers here.
        $this->merchant();

        $this->actingAs($me);

        $this->assertSame($mine->id, (int) Product::factory()->create()->store_id);
    }

    /**
     * With several stores the team owns, no store is the answer, and the row is
     * left unstamped rather than attributed to whichever sorts first.
     */
    public function test_a_row_is_left_unstamped_when_the_team_owns_several_stores(): void
    {
        [$team, , $me] = $this->merchant();
        Store::factory()->create(['team_id' => $team->id]);

        $this->actingAs($me);

        $this->assertNull(Product::factory()->create(['store_id' => null])->store_id);
    }

    /**
     * The dangerous case: a team with no store of its own, on a deployment
     * where exactly one store exists. The single-store shortcut would stamp the
     * row with a store this team does not own — a leak written rather than
     * read, and the harder kind to notice.
     */
    public function test_a_panel_user_whose_team_owns_no_store_does_not_borrow_somebody_elses(): void
    {
        $onlyStore = Store::query()->firstOrFail();
        $storeless = Team::factory()->create();

        $this->actingAs(User::factory()->create(['current_team_id' => $storeless->id]));

        $product = Product::factory()->create(['store_id' => null]);

        $this->assertNotSame((int) $onlyStore->id, (int) $product->store_id);
        $this->assertNull($product->store_id);
    }

    /**
     * Nothing to scope by is not the same as scoping to nothing: a team with no
     * store must still see its rows, or the panel goes blank the moment a
     * merchant is onboarded before their storefront is configured.
     */
    public function test_a_team_with_no_store_is_not_scoped_to_an_empty_result(): void
    {
        $store = Store::query()->firstOrFail();
        Product::factory()->create(['store_id' => $store->id]);

        $this->actingAs(User::factory()->create([
            'current_team_id' => Team::factory()->create()->id,
        ]));

        $this->assertSame(1, Product::query()->count());
    }

    /**
     * A merchant is also a shopper. Browsing a storefront — their own or
     * anyone's — the host answers, not the team they happen to administer.
     */
    public function test_a_resolved_host_answers_before_the_panel_team(): void
    {
        [, $mine, $me] = $this->merchant();
        [, $theirs] = $this->merchant();

        Product::factory()->create(['store_id' => $mine->id]);
        Product::factory()->count(2)->create(['store_id' => $theirs->id]);

        $channel = Channel::factory()->create(['store_id' => $theirs->id]);
        ChannelDomain::factory()->primary()->create([
            'channel_id' => $channel->id,
            'host' => 'theirs.example.com',
        ]);

        $this->actingAs($me);

        // Two products plus the home page: their catalogue, not the one the
        // logged-in user's team owns.
        $body = $this->get('http://theirs.example.com/sitemap.xml')->assertOk()->getContent();

        $this->assertSame(3, substr_count($body, '<loc>'));
    }

    /**
     * Off a host and off a panel — a console command, a queued job — there is
     * nobody to scope by and the scope stays inert.
     */
    public function test_the_scope_is_still_inert_with_no_authenticated_user(): void
    {
        [, $mine] = $this->merchant();
        [, $theirs] = $this->merchant();

        Product::factory()->create(['store_id' => $mine->id]);
        Product::factory()->create(['store_id' => $theirs->id]);

        $this->assertSame(2, Product::query()->count());
    }
}
