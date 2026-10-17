<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\Channels\Pages\EditChannel;
use App\Filament\Admin\Resources\Channels\RelationManagers\DomainsRelationManager;
use App\Filament\Admin\Resources\Stores\Pages\EditStore;
use App\Filament\Admin\Resources\Stores\RelationManagers\ChannelsRelationManager;
use App\Http\Middleware\TrustHosts;
use App\Models\Channel;
use App\Models\ChannelDomain;
use App\Models\Product;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminChannelDomainTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Store $store;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('super_admin', 'web');
        $this->admin = User::factory()->withPersonalTeam()->create()->assignRole('super_admin');
        $this->store = Store::factory()->create(['team_id' => Team::factory()->create()->id]);
        $this->channel = Channel::factory()->create(['store_id' => $this->store->id]);

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->admin->ownedTeams()->first());
        Filament::getPanel('admin')->boot();
    }

    private function domains(): Testable
    {
        return Livewire::test(DomainsRelationManager::class, ['ownerRecord' => $this->channel, 'pageClass' => EditChannel::class]);
    }

    private function addDomain(string $host, bool $primary = false): Testable
    {
        return $this->domains()->callTableAction(CreateAction::class, data: ['host' => $host, 'is_primary' => $primary]);
    }

    public function test_a_merchant_cannot_manage_channels_or_domains(): void
    {
        $merchant = User::factory()->withPersonalTeam()->create();

        $this->assertFalse($merchant->can('viewAny', Channel::class));
        $this->assertFalse($merchant->can('update', $this->channel));
    }

    public function test_a_channel_is_added_to_a_store(): void
    {
        Livewire::test(ChannelsRelationManager::class, ['ownerRecord' => $this->store, 'pageClass' => EditStore::class])
            ->callTableAction(CreateAction::class, data: ['name' => 'Web'])
            ->assertHasNoTableActionErrors();

        $this->assertSame(2, $this->store->channels()->count());
    }

    public function test_a_host_is_normalised_on_save(): void
    {
        $this->addDomain('  Shop.Example.COM:8080 ')->assertHasNoTableActionErrors();

        $this->assertTrue(ChannelDomain::where('host', 'shop.example.com')->exists());
    }

    public function test_invalid_and_duplicate_hosts_are_refused(): void
    {
        $this->addDomain('https://shop.example.com/path')->assertHasTableActionErrors(['host']);
        $this->addDomain('not a host')->assertHasTableActionErrors(['host']);

        $this->addDomain('shop.example.com');
        $this->addDomain('SHOP.example.com')->assertHasTableActionErrors(['host']);
        $this->assertSame(1, ChannelDomain::where('host', 'shop.example.com')->count());
    }

    public function test_a_channel_has_one_primary_domain(): void
    {
        $this->addDomain('one.example.com', primary: true);
        $this->addDomain('two.example.com', primary: true);

        $this->assertSame(['two.example.com'], $this->channel->domains()->where('is_primary', true)->pluck('host')->all());
    }

    public function test_a_mapped_domain_serves_its_store_only_and_is_trusted_until_removed(): void
    {
        Product::factory()->create(['name' => 'Acme Lamp', 'store_id' => $this->store->id]);
        Product::factory()->create(['name' => 'Default Lamp', 'store_id' => Store::where('slug', 'default')->value('id')]);
        $pattern = '^'.preg_quote('shop.example.com').'$';

        $this->addDomain('shop.example.com');

        $this->assertContains($pattern, app(TrustHosts::class)->hosts());
        $this->get('http://shop.example.com/products')->assertOk()->assertSee('Acme Lamp')->assertDontSee('Default Lamp');

        $domain = ChannelDomain::where('host', 'shop.example.com')->firstOrFail();
        $this->domains()->callTableAction(DeleteAction::class, $domain);

        $this->assertNotContains($pattern, app(TrustHosts::class)->hosts());
        $this->get('http://shop.example.com/products')->assertNotFound();
    }

    public function test_a_channel_serving_the_main_site_cannot_be_deleted(): void
    {
        $main = Store::where('slug', 'default')->firstOrFail();

        Livewire::test(ChannelsRelationManager::class, ['ownerRecord' => $main, 'pageClass' => EditStore::class])
            ->assertTableActionHidden(DeleteAction::class, $main->channels()->firstOrFail());
    }
}
