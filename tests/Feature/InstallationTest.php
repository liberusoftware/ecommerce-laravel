<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Team;
use App\Support\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class InstallationTest extends TestCase
{
    use RefreshDatabase;

    private function configure(string $mode, bool $multipleStores = false): void
    {
        config([
            'ecommerce.installation.mode' => $mode,
            'ecommerce.installation.multiple_stores_per_team' => $multipleStores,
        ]);
    }

    public function test_a_fresh_installation_is_single_store_with_one_store_per_team(): void
    {
        $this->assertSame(Installation::SINGLE, Installation::mode());
        $this->assertFalse(Installation::allowsMultipleStoresPerTeam());
    }

    public function test_an_unknown_mode_is_a_configuration_error(): void
    {
        $this->configure('franchise');

        $this->expectException(InvalidArgumentException::class);
        Installation::mode();
    }

    public function test_a_single_store_installation_allows_one_store_in_total(): void
    {
        $this->configure(Installation::SINGLE, multipleStores: true);
        $team = Team::factory()->create();

        // The migration's default store already exists.
        $this->assertFalse(Installation::canCreateStoreFor($team));

        Store::query()->delete();
        $this->assertTrue(Installation::canCreateStoreFor($team));
    }

    public function test_saas_allows_one_store_per_team_unless_multiple_stores_are_enabled(): void
    {
        $this->configure(Installation::SAAS);
        $team = Team::factory()->create();

        $this->assertTrue(Installation::canCreateStoreFor($team));
        Store::factory()->create(['team_id' => $team->id]);
        $this->assertFalse(Installation::canCreateStoreFor($team));
        $this->assertTrue(Installation::canCreateStoreFor(Team::factory()->create()));

        $this->configure(Installation::SAAS, multipleStores: true);
        $this->assertTrue(Installation::canCreateStoreFor($team));
    }

    public function test_a_marketplace_applies_the_same_per_team_limit(): void
    {
        $this->configure(Installation::MARKETPLACE);
        $team = Team::factory()->create();
        Store::factory()->create(['team_id' => $team->id]);

        $this->assertFalse(Installation::canCreateStoreFor($team));
    }

    public function test_a_team_lists_its_stores(): void
    {
        $team = Team::factory()->create();
        $store = Store::factory()->create(['team_id' => $team->id]);
        Store::factory()->create();

        $this->assertSame([$store->id], $team->stores()->pluck('id')->all());
    }
}
