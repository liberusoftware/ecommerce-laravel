<?php

namespace Tests\Feature;

use App\Actions\Jetstream\CreateTeam;
use App\Filament\App\Pages\CreateTeam as RegisterStore;
use App\Filament\App\Resources\Orders\OrderResource;
use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Support\Installation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** A merchant can sign up and run their store with no help from the platform, and reaches nothing else. */
class MerchantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function saas(): void
    {
        config(['ecommerce.installation.mode' => Installation::SAAS]);
    }

    public function test_a_new_user_on_a_saas_installation_can_register_a_store_and_sell(): void
    {
        $this->saas();
        $user = User::factory()->create();

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('app')));

        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Livewire::test(RegisterStore::class)
            ->fillForm(['name' => 'Fern & Field'])
            ->call('register')
            ->assertHasNoFormErrors();

        $team = $user->fresh()->ownedTeams()->sole();
        $store = $team->stores()->sole();
        Filament::setTenant($team);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Seed Tin', 'slug' => 'seed-tin', 'pricing_type' => 'fixed', 'price' => 12,
                'inventory_count' => 3, 'low_stock_threshold' => 0, 'status' => Product::STATUS_ACTIVE,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutGlobalScopes()->where('slug', 'seed-tin')->sole();
        $this->assertSame($store->id, $product->store_id);
        $this->assertSame($team->id, $product->team_id);
        $this->assertTrue(OrderResource::canViewAny());
    }

    public function test_a_single_merchant_installation_offers_no_self_service_sign_up(): void
    {
        config(['ecommerce.installation.mode' => Installation::SINGLE]);
        $user = User::factory()->create();

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('app')));
        $this->actingAs($user)->get('/app')->assertForbidden();
    }

    public function test_the_owner_of_a_team_the_platform_provisioned_can_manage_it_straight_away(): void
    {
        $this->saas();
        $owner = User::factory()->create();
        $team = Team::factory()->create(['user_id' => $owner->id, 'personal_team' => false]);
        Installation::provisionStoreFor($team);

        $this->actingAs($owner)->get("/app/{$team->id}/products")->assertOk();
        $this->actingAs($owner)->get("/app/{$team->id}/products/create")->assertOk();
        $this->actingAs($owner)->get("/app/{$team->id}/store-settings")->assertOk();
    }

    public function test_an_ordinary_member_gets_only_their_roles_screens(): void
    {
        $this->saas();
        $owner = User::factory()->create();
        $team = (new CreateTeam)->create($owner, ['name' => 'Acme']);
        $packer = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($packer, ['role' => 'orders']);

        $this->actingAs($packer)->get("/app/{$team->id}/orders")->assertOk();
        $this->actingAs($packer)->get("/app/{$team->id}/products/create")->assertForbidden();
        $this->actingAs($packer)->get("/app/{$team->id}/store-settings")->assertForbidden();
        $this->actingAs($packer)->get("/app/{$team->id}/shipping-methods")->assertForbidden();
    }

    public function test_another_teams_product_cannot_be_opened_by_changing_the_url(): void
    {
        $this->saas();
        $mine = (new CreateTeam)->create($me = User::factory()->create(), ['name' => 'Mine']);
        $theirs = (new CreateTeam)->create(User::factory()->create(), ['name' => 'Theirs']);
        $product = Product::factory()->create();
        $product->forceFill(['store_id' => $theirs->stores()->sole()->id, 'team_id' => $theirs->id])->save();

        $this->actingAs($me)->get(ProductResource::getUrl('edit', ['record' => $product], tenant: $mine, panel: 'app'))->assertNotFound();
        $this->actingAs($me)->get("/app/{$theirs->id}/products")->assertNotFound();
    }

    public function test_a_store_admin_cannot_change_another_stores_orders_through_the_api(): void
    {
        $this->saas();
        $mine = (new CreateTeam)->create($me = User::factory()->create(), ['name' => 'Mine']);
        $theirs = (new CreateTeam)->create(User::factory()->create(), ['name' => 'Theirs']);
        $theirStore = $theirs->stores()->sole();
        ChannelDomain::factory()->primary()->create([
            'channel_id' => Channel::factory()->create(['store_id' => $theirStore->id])->id, 'host' => 'theirs.test',
        ]);
        $order = Order::create(['customer_email' => 'buyer@example.com', 'total_amount' => 50, 'status' => Order::STATUS_PAID]);
        $order->forceFill(['store_id' => $theirStore->id, 'team_id' => $theirs->id])->save();

        Sanctum::actingAs($me);
        $this->postJson('http://theirs.test/api/orders/'.$order->id.'/status', ['status' => 'completed'])->assertForbidden();
        $this->postJson('http://localhost/api/orders/'.$order->id.'/status', ['status' => 'completed'])->assertNotFound();
        $this->postJson('http://theirs.test/api/products', [
            'name' => 'Planted', 'short_description' => 'x', 'price' => 1, 'category_id' => 1,
        ])->assertForbidden();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_a_store_admin_manages_their_own_orders_through_the_api(): void
    {
        $this->saas();
        $mine = (new CreateTeam)->create($me = User::factory()->create(), ['name' => 'Mine']);
        $store = $mine->stores()->sole();
        ChannelDomain::factory()->primary()->create([
            'channel_id' => Channel::factory()->create(['store_id' => $store->id])->id, 'host' => 'mine.test',
        ]);
        $order = Order::create(['customer_email' => 'buyer@example.com', 'total_amount' => 50, 'status' => Order::STATUS_PAID]);
        $order->forceFill(['store_id' => $store->id, 'team_id' => $mine->id])->save();

        Sanctum::actingAs($me);
        $this->postJson('http://mine.test/api/orders/'.$order->id.'/status', ['status' => 'completed'])->assertOk();
        $this->assertSame(Order::STATUS_COMPLETED, $order->fresh()->status);
    }

    public function test_drafts_are_hidden_from_shoppers_on_the_product_api(): void
    {
        $draft = Product::factory()->create(['status' => Product::STATUS_DRAFT, 'name' => 'Secret Draft']);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/products')->assertJsonMissing(['name' => 'Secret Draft']);
        $this->getJson('/api/products/'.$draft->id)->assertNotFound();
    }

    public function test_a_users_memberships_in_several_teams_stay_separate(): void
    {
        $this->saas();
        $user = User::factory()->create();
        $editorHere = Team::factory()->create();
        $viewerThere = Team::factory()->create();
        $editorHere->users()->attach($user, ['role' => 'catalogue']);
        $viewerThere->users()->attach($user, ['role' => 'viewer']);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        Filament::setTenant($editorHere);
        $this->assertTrue(ProductResource::canCreate());

        Filament::setTenant($viewerThere);
        $this->assertFalse(ProductResource::canCreate());
        $this->assertTrue(ProductResource::canViewAny());
        $this->assertFalse(Store::query()->exists() && OrderResource::canCreate());
    }
}
