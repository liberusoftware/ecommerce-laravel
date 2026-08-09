<?php

namespace Tests\Feature;

use App\Models\ChatAnalytics;
use App\Models\ChatConversation;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The leak `AdminPanelTenancyTest` was built to catch and could not.
 *
 * That test asks whether an unscoped resource *declared its own* exemption,
 * because the failure it was written for was one resource's `scopeToTenant(false)`
 * silently unscoping every other. `ChatConversationResource` declared its
 * exemption, honestly, with a comment explaining that `chat_conversations` had
 * no `team_id` — so the ratchet passed, and staff on any team still read every
 * team's conversations, customer names, emails and message bodies included.
 *
 * An honest opt-out is still a leak when the reason for it is a missing column
 * rather than data that genuinely spans tenants. So the assertion here is about
 * the data, not the declaration: rows, through the model, as a panel user.
 */
class ChatConversationTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Store, 1: User}
     */
    private function merchant(): array
    {
        $team = Team::factory()->create();
        $store = Store::factory()->create(['team_id' => $team->id]);
        $user = User::factory()->create(['current_team_id' => $team->id]);

        return [$store, $user];
    }

    public function test_a_panel_user_does_not_see_another_teams_conversations(): void
    {
        [$mine, $me] = $this->merchant();
        [$theirs] = $this->merchant();

        $ours = ChatConversation::factory()->create(['store_id' => $mine->id]);
        $hidden = ChatConversation::factory()->create([
            'store_id' => $theirs->id,
            'customer_email' => 'someone-elses-customer@example.com',
        ]);

        $this->actingAs($me);

        $visible = ChatConversation::query()->pluck('id')->all();

        $this->assertSame([$ours->id], $visible);
        $this->assertNotContains($hidden->id, $visible);
    }

    /**
     * The two averages have no tenant key of their own — they hang off the
     * conversation — and both callers are panel surfaces (`ChatStatsWidget`,
     * `ChatAgentDashboard`), which is exactly where Filament's resource
     * tenancy does not reach.
     */
    public function test_the_analytics_averages_do_not_aggregate_across_teams(): void
    {
        [$mine, $me] = $this->merchant();
        [$theirs] = $this->merchant();

        ChatAnalytics::factory()->create([
            'conversation_id' => ChatConversation::factory()->create(['store_id' => $mine->id])->id,
            'response_time_seconds' => 10,
            'satisfaction_rating' => 5,
        ]);

        ChatAnalytics::factory()->create([
            'conversation_id' => ChatConversation::factory()->create(['store_id' => $theirs->id])->id,
            'response_time_seconds' => 990,
            'satisfaction_rating' => 1,
        ]);

        $this->actingAs($me);

        // 10, not 500 — the other team's row must not move our average.
        $this->assertSame(10.0, (float) ChatAnalytics::averageResponseTime());
        $this->assertSame(5.0, (float) ChatAnalytics::averageSatisfactionRating());
    }

    /**
     * `team_id` and `store_id` are absent from `$fillable` on purpose: the
     * traits' `creating` hooks are the only writers. A request that could post
     * either one could post itself into another merchant's tenancy.
     */
    public function test_the_tenant_keys_cannot_be_mass_assigned(): void
    {
        [$mine, $me] = $this->merchant();
        [$theirs] = $this->merchant();

        $this->actingAs($me);

        $conversation = ChatConversation::create([
            'session_id' => 'attempted-cross-tenant',
            'store_id' => $theirs->id,
            'team_id' => $theirs->team_id,
            'status' => 'queued',
        ]);

        $this->assertNotSame($theirs->id, $conversation->store_id);
        $this->assertNotSame($theirs->team_id, $conversation->team_id);
    }
}
