<?php

namespace Tests\Unit;

use App\Models\LoyaltyPoints;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyReward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyRewardModelTest extends TestCase
{
    use RefreshDatabase;

    private LoyaltyProgram $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->program = LoyaltyProgram::create([
            'name' => 'Reward Test Program',
            'points_per_dollar' => 1,
            'points_value' => 0.01,
            'is_active' => true,
        ]);
    }

    private function makeReward(array $overrides = []): LoyaltyReward
    {
        return LoyaltyReward::create(array_merge([
            'loyalty_program_id' => $this->program->id,
            'name' => '10% Off Coupon',
            'reward_type' => 'discount',
            'discount_value' => 10.00,
            'points_cost' => 500,
            'is_active' => true,
        ], $overrides));
    }

    public function test_reward_can_be_created(): void
    {
        $reward = $this->makeReward();

        $this->assertInstanceOf(LoyaltyReward::class, $reward);
        $this->assertEquals('10% Off Coupon', $reward->name);
    }

    public function test_is_available_returns_true_for_active_reward(): void
    {
        $reward = $this->makeReward(['is_active' => true, 'stock_quantity' => null]);

        $this->assertTrue($reward->isAvailable());
    }

    public function test_is_available_returns_false_when_inactive(): void
    {
        $reward = $this->makeReward(['is_active' => false]);

        $this->assertFalse($reward->isAvailable());
    }

    public function test_is_available_returns_false_when_stock_depleted(): void
    {
        $reward = $this->makeReward(['stock_quantity' => 0]);

        $this->assertFalse($reward->isAvailable());
    }

    public function test_is_available_returns_false_before_start(): void
    {
        $reward = $this->makeReward([
            'available_from' => now()->addDays(5),
        ]);

        $this->assertFalse($reward->isAvailable());
    }

    public function test_is_available_returns_false_after_end(): void
    {
        $reward = $this->makeReward([
            'available_until' => now()->subDay(),
        ]);

        $this->assertFalse($reward->isAvailable());
    }

    public function test_redeem_returns_null_when_unavailable(): void
    {
        $user = User::factory()->create();
        $reward = $this->makeReward(['is_active' => false]);

        $result = $reward->redeem($user->id);

        $this->assertNull($result);
    }

    public function test_redeem_returns_null_when_insufficient_points(): void
    {
        $user = User::factory()->create();
        LoyaltyPoints::create([
            'user_id' => $user->id,
            'loyalty_program_id' => $this->program->id,
            'balance' => 100,
        ]);

        $reward = $this->makeReward(['points_cost' => 500]);

        $result = $reward->redeem($user->id);

        $this->assertNull($result);
    }

    public function test_redeem_success_deducts_points_stock_and_records_redemption(): void
    {
        $user = User::factory()->create();
        $points = LoyaltyPoints::create([
            'user_id' => $user->id,
            'loyalty_program_id' => $this->program->id,
            'balance' => 600,
            'lifetime_earned' => 600,
            'lifetime_redeemed' => 0,
        ]);
        $reward = $this->makeReward(['points_cost' => 500, 'stock_quantity' => 3]);

        $redemption = $reward->redeem($user->id);

        $this->assertNotNull($redemption);
        $this->assertEquals(500, $redemption->points_spent);
        $this->assertEquals('pending', $redemption->status);
        // Points deducted exactly once.
        $this->assertEquals(100, $points->fresh()->balance);
        $this->assertEquals(500, $points->fresh()->lifetime_redeemed);
        // Stock decremented once.
        $this->assertEquals(2, $reward->fresh()->stock_quantity);
    }

    public function test_redeem_twice_respects_max_redemptions_and_deducts_once(): void
    {
        $user = User::factory()->create();
        $points = LoyaltyPoints::create([
            'user_id' => $user->id,
            'loyalty_program_id' => $this->program->id,
            'balance' => 2000,
            'lifetime_earned' => 2000,
        ]);
        $reward = $this->makeReward(['points_cost' => 500, 'max_redemptions' => 1]);

        $first = $reward->redeem($user->id);
        $second = $reward->redeem($user->id);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertEquals(1500, $points->fresh()->balance);
    }

    /**
     * A failed redemption leaves nothing behind — no ledger row, no stock movement,
     * no redemption record.
     *
     * The race this guards against is not reproducible in a single-threaded test:
     * two callers reading `stock_quantity = 1` before either decrements it needs two
     * connections. What is testable is the property the lock exists to keep — that
     * the four writes are one act — so this pins the all-or-nothing shape, and a
     * future edit that pulls a write back out of the transaction fails here.
     */
    public function test_a_failed_redemption_moves_nothing(): void
    {
        $user = User::factory()->create();
        $points = LoyaltyPoints::create([
            'user_id' => $user->id,
            'loyalty_program_id' => $this->program->id,
            'balance' => 100,
        ]);
        $reward = $this->makeReward(['points_cost' => 500, 'stock_quantity' => 1]);

        $this->assertNull($reward->redeem($user->id));

        $this->assertEquals(100, $points->fresh()->balance);
        $this->assertEquals(0, $points->transactions()->count(), 'no ledger row for a redemption that did not happen');
        $this->assertEquals(1, $reward->fresh()->stock_quantity, 'stock is not spent on a failed redemption');
        $this->assertEquals(0, $reward->redemptions()->count());
    }

    /**
     * The last unit is spent once. `isAvailable()` and the decrement used to sit
     * either side of the points debit with nothing holding the row still between
     * them; the second call now reads the first one's result.
     */
    public function test_the_last_unit_of_stock_is_redeemed_once(): void
    {
        $user = User::factory()->create();
        $points = LoyaltyPoints::create([
            'user_id' => $user->id,
            'loyalty_program_id' => $this->program->id,
            'balance' => 2000,
            'lifetime_earned' => 2000,
        ]);
        $reward = $this->makeReward(['points_cost' => 500, 'stock_quantity' => 1]);

        $this->assertNotNull($reward->redeem($user->id));
        $this->assertNull($reward->redeem($user->id));

        $this->assertEquals(0, $reward->fresh()->stock_quantity, 'stock never goes negative');
        $this->assertEquals(1500, $points->fresh()->balance);
        $this->assertEquals(1, $reward->redemptions()->count());
    }

    public function test_belongs_to_program(): void
    {
        $reward = $this->makeReward();

        $this->assertInstanceOf(LoyaltyProgram::class, $reward->program);
    }
}
