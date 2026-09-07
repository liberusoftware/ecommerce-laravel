<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The command that answers [#944](https://github.com/liberusoftware/ecommerce-laravel/issues/944).
 *
 * Its output decides whether rows are attributed or quarantined in wave 2, so
 * what is worth testing is not that it prints a table — it is that it counts a
 * cross-boundary row as one and does not count a legitimate row as one. A
 * checklist that cries wolf gets ignored; one that stays quiet gets believed.
 *
 * Written against `DB` rather than factories throughout, for the same reason the
 * command is: several of these tables have no factory, and the point is what is
 * in the database rather than what the models would have written.
 */
class TenantDistributionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_a_row_whose_parent_belongs_to_another_team(): void
    {
        [$mine, $theirs] = [$this->merchant(), $this->merchant()];

        $product = Product::factory()->create(['team_id' => $theirs->id]);

        // Owned by the first merchant, pointing at the second merchant's
        // product. The user is the first merchant's owner, so the membership
        // check stays quiet and the parent check is the only thing that fires.
        $this->wishlist($mine, $product->id);

        $this->artisan('tenants:distribution')
            ->expectsOutputToContain('One row is already attributed across a tenancy boundary')
            ->expectsOutputToContain('| wishlists | product_id → products | 1 |')
            ->assertSuccessful();
    }

    public function test_a_row_created_by_the_team_owner_is_not_a_breach(): void
    {
        // A team's owner has no row in its own `team_user` pivot, so a naive
        // membership check reads every row the merchant created themselves as a
        // breach — which on a real deployment is most of them, and is the
        // reason nobody would trust the output.
        $team = $this->merchant();

        $this->wishlist($team, Product::factory()->create(['team_id' => $team->id])->id);

        $this->artisan('tenants:distribution')
            ->expectsOutputToContain('None found.')
            ->assertSuccessful();
    }

    public function test_it_counts_rows_belonging_to_nobody_separately(): void
    {
        $team = $this->merchant();

        $this->customer($team->id);
        $this->customer(null);

        // Belonging to nobody is a different state from belonging to team 1,
        // and summing the two is how wave 2 would attribute a row it has no
        // evidence for.
        $this->artisan('tenants:distribution')
            ->expectsOutputToContain('| customers | NULL | 1 |')
            ->expectsOutputToContain("| customers | {$team->id} | 1 |")
            ->assertSuccessful();
    }

    private function merchant(): Team
    {
        return User::factory()->withPersonalTeam()->create()->ownedTeams()->first();
    }

    private function wishlist(Team $team, int $productId): void
    {
        DB::table('wishlists')->insert([
            'user_id' => $team->user_id,
            'product_id' => $productId,
            'team_id' => $team->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(?int $teamId): void
    {
        DB::table('customers')->insert([
            'first_name' => 'A',
            'last_name' => 'Merchant',
            'email' => 'customer'.($teamId ?? 'none').'@example.com',
            'phone_number' => 123456,
            'address' => '1 Street',
            'city' => 'Town',
            'state' => 'County',
            'postal_code' => 'AB1 2CD',
            'team_id' => $teamId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
