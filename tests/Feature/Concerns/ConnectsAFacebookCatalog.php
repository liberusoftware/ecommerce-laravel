<?php

namespace Tests\Feature\Concerns;

use App\Models\FacebookConnection;
use App\Models\Product;
use App\Models\Team;

/**
 * A connected merchant. The connection is per Team, so every fixture here has
 * to say which Team it belongs to — that is the whole difference from the
 * single-tenant original.
 */
trait ConnectsAFacebookCatalog
{
    protected Team $team;

    protected function connectTeam(string $catalogId = 'CAT123'): FacebookConnection
    {
        $team = Team::factory()->create();

        return FacebookConnection::forceCreate([
            'team_id' => $team->id,
            'access_token' => 'test-token',
            'catalog_id' => $catalogId,
            'business_id' => 'BIZ1',
            'graph_version' => 'v21.0',
        ]);
    }

    protected function connectedTeam(): Team
    {
        return $this->team = $this->connectTeam()->team;
    }

    /** A Product owned by the Team currently under test. */
    protected function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'inventory_count' => 5,
            'list_on_facebook' => false,
        ], $overrides));
    }
}
