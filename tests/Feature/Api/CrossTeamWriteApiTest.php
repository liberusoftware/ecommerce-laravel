<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductCollection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `admin` and `super_admin` are global roles — `config/permission.php` sets
 * `'teams' => false` — so the role check on the API write paths says "this
 * person administers something", never "this person administers *this*".
 *
 * Before this, an admin of one merchant could edit and soft-delete another
 * merchant's catalogue (#939).
 */
class CrossTeamWriteApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Team $ownTeam;

    private Team $otherTeam;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');

        $this->admin = User::factory()->create()->assignRole('super_admin');
        $this->ownTeam = Team::factory()->create(['user_id' => $this->admin->id]);
        $this->admin->forceFill(['current_team_id' => $this->ownTeam->id])->save();

        // Owned by somebody else entirely.
        $this->otherTeam = Team::factory()->create(['user_id' => User::factory()->create()->id]);

        Sanctum::actingAs($this->admin);
    }

    public function test_admin_cannot_update_another_teams_product(): void
    {
        $product = Product::factory()->create(['team_id' => $this->otherTeam->id, 'price' => 10]);

        $this->putJson("/api/products/{$product->id}", ['price' => 1])->assertNotFound();

        $this->assertSame('10.00', (string) $product->fresh()->price);
    }

    public function test_admin_cannot_delete_another_teams_product(): void
    {
        $product = Product::factory()->create(['team_id' => $this->otherTeam->id]);

        $this->deleteJson("/api/products/{$product->id}")->assertNotFound();

        $this->assertNotNull(Product::find($product->id));
    }

    public function test_admin_can_still_update_their_own_teams_product(): void
    {
        $product = Product::factory()->create(['team_id' => $this->ownTeam->id, 'price' => 10]);

        $this->putJson("/api/products/{$product->id}", ['price' => 1])->assertOk();

        $this->assertSame('1.00', (string) $product->fresh()->price);
    }

    public function test_a_created_product_belongs_to_the_creating_admins_team(): void
    {
        $category = ProductCategory::factory()->create();

        $response = $this->postJson('/api/products', [
            'name' => 'Stamped',
            'short_description' => 'x',
            'price' => 5,
            'category_id' => $category->id,
        ])->assertCreated();

        $this->assertSame(
            $this->ownTeam->id,
            (int) Product::find($response->json('data.id'))->team_id,
        );
    }

    public function test_admin_cannot_update_or_delete_another_teams_collection(): void
    {
        $collection = ProductCollection::factory()->create(['team_id' => $this->otherTeam->id, 'name' => 'Theirs']);

        $this->putJson("/api/collections/{$collection->id}", ['name' => 'Mine now'])->assertNotFound();
        $this->deleteJson("/api/collections/{$collection->id}")->assertNotFound();

        $this->assertSame('Theirs', $collection->fresh()->name);
    }

    public function test_admin_cannot_move_products_into_another_teams_collection(): void
    {
        $collection = ProductCollection::factory()->create(['team_id' => $this->otherTeam->id]);
        $product = Product::factory()->create(['team_id' => $this->ownTeam->id]);

        $this->postJson("/api/collections/{$collection->id}/products", ['product_ids' => [$product->id]])
            ->assertNotFound();
    }

    /**
     * The check is ownership, not the absence of a team: an admin with no team
     * at all administers nothing rather than everything.
     */
    public function test_a_teamless_admin_cannot_write_to_a_teams_product(): void
    {
        $teamless = User::factory()->create()->assignRole('super_admin');
        Sanctum::actingAs($teamless);

        $product = Product::factory()->create(['team_id' => $this->ownTeam->id]);

        $this->deleteJson("/api/products/{$product->id}")->assertNotFound();
    }
}
