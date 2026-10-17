<?php

namespace Tests\Feature\Filament;

use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantStorefrontPageTest extends TestCase
{
    use RefreshDatabase;

    private function storeWithDomain(Team $team, string $host): Store
    {
        $store = Store::factory()->create(['team_id' => $team->id, 'name' => ucfirst(strtok($host, '.')).' Store']);
        $channel = Channel::factory()->create(['store_id' => $store->id]);
        ChannelDomain::factory()->create(['channel_id' => $channel->id, 'host' => $host, 'is_primary' => true]);

        return $store;
    }

    public function test_a_merchant_sees_only_their_own_storefront_addresses(): void
    {
        $mine = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $mine->id]);
        $mine->users()->attach($user, ['role' => 'admin']);
        $this->storeWithDomain($mine, 'acme.example.com');
        $this->storeWithDomain(Team::factory()->create(), 'rival.example.com');

        $this->actingAs($user)
            ->get("/app/{$mine->id}/storefront")
            ->assertOk()
            ->assertSee('Acme Store')
            ->assertSee('acme.example.com')
            ->assertDontSee('rival.example.com')
            ->assertDontSee('Rival Store');
    }

    public function test_a_store_with_no_domain_is_shown_as_not_public(): void
    {
        $mine = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $mine->id]);
        $mine->users()->attach($user, ['role' => 'admin']);
        Store::factory()->create(['team_id' => $mine->id, 'name' => 'Quiet Store']);

        $this->actingAs($user)
            ->get("/app/{$mine->id}/storefront")
            ->assertOk()
            ->assertSee('Quiet Store')
            ->assertSee('Not public yet');
    }
}
