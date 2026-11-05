<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Resources\ContactRequests\ContactRequestResource;
use App\Filament\App\Resources\ContactRequests\Pages\ListContactRequests;
use App\Filament\App\Resources\ContactRequests\Pages\ViewContactRequest;
use App\Models\ContactRequest;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Services\StoreContext;
use App\Support\TeamPermission;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** The merchant reads what shoppers sent, and only their own store's. */
class ContactInboxTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private ContactRequest $mine;

    private ContactRequest $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();
        $this->team = Team::factory()->create(['user_id' => $owner->id]);
        $store = Store::factory()->create(['team_id' => $this->team->id]);
        $other = Store::factory()->create(['team_id' => Team::factory()->create()->id]);

        $this->mine = StoreContext::writingTo($store->id, fn () => ContactRequest::create(['name' => 'Ada', 'email' => 'ada@example.test', 'subject' => 'Sizing', 'message' => 'Does the M run small?']));
        StoreContext::writingTo($store->id, fn () => ContactRequest::create(['name' => 'Grace', 'email' => 'grace@example.test', 'message' => 'Do you ship to Ireland?']));
        $this->theirs = StoreContext::writingTo($other->id, fn () => ContactRequest::create(['name' => 'Eve', 'email' => 'eve@example.test', 'message' => 'Another merchant entirely.']));

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->team);
    }

    public function test_the_inbox_lists_this_stores_messages_with_an_unread_count(): void
    {
        Livewire::test(ListContactRequests::class)
            ->assertCanSeeTableRecords([$this->mine])
            ->assertCanNotSeeTableRecords([$this->theirs]);

        $this->assertSame('2', ContactRequestResource::getNavigationBadge());
    }

    public function test_opening_a_message_marks_it_read(): void
    {
        Livewire::test(ViewContactRequest::class, ['record' => $this->mine->getKey()])
            ->assertOk()
            ->assertSee('Does the M run small?');

        $this->assertNotNull($this->mine->fresh()->read_at);
        $this->assertSame('1', ContactRequestResource::getNavigationBadge());
    }

    public function test_a_catalogue_manager_cannot_read_customer_messages(): void
    {
        $member = User::factory()->create();
        $this->team->users()->attach($member, ['role' => TeamPermission::ROLE_CATALOGUE]);
        $this->actingAs($member);

        $this->assertFalse(ContactRequestResource::canViewAny());
    }
}
