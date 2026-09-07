<?php

namespace Tests\Unit;

use App\Models\LoyaltyPoints;
use App\Models\LoyaltyPointTransaction;
use App\Models\LoyaltyProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyPointsTest extends TestCase
{
    use RefreshDatabase;

    private LoyaltyProgram $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->program = LoyaltyProgram::create([
            'name' => 'Test Program',
            'points_per_dollar' => 1.0,
            'points_value' => 0.01,
            'points_expiry_days' => 365,
            'min_points_redemption' => 100,
            'is_active' => true,
        ]);
    }

    private function makePoints(array $overrides = []): LoyaltyPoints
    {
        $user = User::factory()->create();

        return LoyaltyPoints::create(array_merge([
            'user_id' => $user->id,
            'loyalty_program_id' => $this->program->id,
            'balance' => 0,
            'lifetime_earned' => 0,
            'lifetime_redeemed' => 0,
        ], $overrides));
    }

    public function test_add_points_increases_balance(): void
    {
        $points = $this->makePoints();

        $points->addPoints(50, 'purchase', 'Order #1');

        $this->assertEquals(50, $points->fresh()->balance);
        $this->assertEquals(50, $points->fresh()->lifetime_earned);
    }

    public function test_add_points_creates_transaction(): void
    {
        $points = $this->makePoints();

        $points->addPoints(100, 'purchase', 'Order #2');

        $this->assertCount(1, $points->transactions);
        $this->assertEquals(100, $points->transactions->first()->points);
        $this->assertEquals('purchase', $points->transactions->first()->type);
    }

    public function test_redeem_points_decreases_balance(): void
    {
        $points = $this->makePoints(['balance' => 200, 'lifetime_earned' => 200]);

        $result = $points->redeemPoints(100, 'Coupon redemption');

        $this->assertTrue($result);
        $this->assertEquals(100, $points->fresh()->balance);
        $this->assertEquals(100, $points->fresh()->lifetime_redeemed);
    }

    public function test_redeem_points_returns_false_when_insufficient(): void
    {
        $points = $this->makePoints(['balance' => 50]);

        $result = $points->redeemPoints(100, 'test');

        $this->assertFalse($result);
        $this->assertEquals(50, $points->fresh()->balance);
    }

    public function test_redeem_points_creates_negative_transaction(): void
    {
        $points = $this->makePoints(['balance' => 200, 'lifetime_earned' => 200]);

        $points->redeemPoints(50, 'test');

        $transaction = $points->transactions()->first();
        $this->assertEquals(-50, $transaction->points);
        $this->assertEquals('redeemed', $transaction->type);
    }

    private function seedExpiredEarnedLot(LoyaltyPoints $points, int $amount): LoyaltyPointTransaction
    {
        return LoyaltyPointTransaction::create([
            'loyalty_points_id' => $points->id,
            'points' => $amount,
            'type' => 'earned',
            'expires_at' => now()->subDay(),
            'is_expired' => false,
        ]);
    }

    public function test_expire_points_removes_expired_lot_from_balance(): void
    {
        $points = $this->makePoints(['balance' => 100, 'lifetime_earned' => 100]);
        $this->seedExpiredEarnedLot($points, 100);

        $points->expirePoints();

        $this->assertEquals(0, $points->fresh()->balance);
        $this->assertTrue($points->transactions()->where('type', 'earned')->first()->is_expired);
        $this->assertEquals(-100, $points->transactions()->where('type', 'expired')->first()->points);
    }

    public function test_expire_points_never_drives_balance_negative_when_lot_partly_spent(): void
    {
        $points = $this->makePoints(['balance' => 100, 'lifetime_earned' => 100]);
        $this->seedExpiredEarnedLot($points, 100);

        // 60 of the earned lot was already redeemed, leaving 40 in the balance.
        $points->redeemPoints(60, 'spent some');
        $this->assertEquals(40, $points->fresh()->balance);

        $points->fresh()->expirePoints();

        $balance = $points->fresh()->balance;
        $this->assertGreaterThanOrEqual(0, $balance, 'expiry must never make the balance negative');
        $this->assertEquals(0, $balance);
        // Ledger stays consistent: earned +100, redeemed -60, expired -40 => 0.
        $this->assertEquals(0, $points->transactions()->sum('points'));
    }

    /**
     * The regression that mattered: every other expiry test in this file builds its
     * own `LoyaltyPointTransaction` with `'type' => 'earned'`, which is a row shape
     * the writer only produces if its caller happens to pass that exact string.
     * `addPoints()` takes `$type` unvalidated, this file's own tests pass
     * `'purchase'`, and the old `where('type', 'earned')` therefore skipped them —
     * points that could never expire, and a liability with no ceiling.
     *
     * So this one earns its points through the writer instead of around it, which
     * is the only arrangement that proves the two agree.
     */
    public function test_points_earned_under_any_label_still_expire(): void
    {
        $points = $this->makePoints();
        $points->addPoints(100, 'purchase', 'Order #1');

        $this->assertEquals(100, $points->fresh()->balance);

        $this->travel(366)->days();
        $points->fresh()->expirePoints();

        $this->assertEquals(0, $points->fresh()->balance, 'a lot expires on its date, not on its label');
        $this->assertEquals(0, $points->transactions()->sum('points'));
    }

    public function test_expire_points_is_idempotent(): void
    {
        $points = $this->makePoints(['balance' => 100, 'lifetime_earned' => 100]);
        $this->seedExpiredEarnedLot($points, 100);

        $points->expirePoints();
        $points->fresh()->expirePoints();

        $this->assertEquals(0, $points->fresh()->balance);
        // Only one expiry ledger row despite two calls.
        $this->assertEquals(1, $points->transactions()->where('type', 'expired')->count());
    }

    public function test_points_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $points = LoyaltyPoints::create([
            'user_id' => $user->id,
            'loyalty_program_id' => $this->program->id,
            'balance' => 0,
            'lifetime_earned' => 0,
            'lifetime_redeemed' => 0,
        ]);

        $this->assertInstanceOf(User::class, $points->user);
        $this->assertEquals($user->id, $points->user->id);
    }

    public function test_points_belongs_to_program(): void
    {
        $points = $this->makePoints();

        $this->assertInstanceOf(LoyaltyProgram::class, $points->program);
        $this->assertEquals($this->program->id, $points->program->id);
    }
}
