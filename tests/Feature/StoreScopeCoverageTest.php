<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\PaymentMethod;
use App\Traits\IsStoreScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Throwable;

/**
 * The ratchet for wave 1.5, step 3.
 *
 * Scoping at the caller failed because it had to be remembered every time. A
 * sweep across sixteen tables has the same shape one level up: it has to be
 * remembered for every model, and the one that gets missed is the leak. So the
 * rule is checked rather than trusted.
 *
 * Both directions, because both have already gone wrong here:
 *
 * - a table with `store_id` whose model is unscoped is an open leak;
 * - a model scoped against a table with no `store_id` is #958 — a query naming
 *   a column that is not there, which fails at request time and only for the
 *   paths nobody tested.
 */
class StoreScopeCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Models that carry `store_id` and are deliberately not scoped by it.
     *
     * Entries may be removed; adding one means writing down why, which is the
     * point. A list that can only shrink is the difference between an exemption
     * and a hole.
     */
    private const NOT_SCOPED_BY_STORE = [
        PaymentMethod::class => 'A shopper\'s saved payment method belongs to the person, not the merchant. '
            .'The column is there because a blanket migration put it on every table with a `team_id`, '
            .'not because the data is store-grained. Scoped, their card would disappear the moment they '
            .'shopped on another storefront.',

        Channel::class => 'Resolving a channel is what produces the scope. Scoping channels by the store '
            .'the channel resolves is circular: nothing would resolve, so nothing would ever be in scope.',
    ];

    public function test_every_table_with_a_store_id_has_a_scoped_model_or_a_recorded_reason(): void
    {
        $unscoped = [];

        foreach ($this->models() as $class => $table) {
            if (! Schema::hasColumn($table, 'store_id')) {
                continue;
            }

            if (array_key_exists($class, self::NOT_SCOPED_BY_STORE) || $this->isScoped($class)) {
                continue;
            }

            $unscoped[] = "{$class} ({$table})";
        }

        sort($unscoped);

        $this->assertSame([], $unscoped, implode("\n", [
            'These models sit on a table with a `store_id` and are not scoped by it.',
            'Either add App\Traits\IsStoreScoped, or record the reason in NOT_SCOPED_BY_STORE',
            'the way payment_methods is recorded — with the reason, not just the name.',
        ]));
    }

    public function test_no_model_is_scoped_against_a_table_that_has_no_store_id(): void
    {
        $missing = [];

        foreach ($this->models() as $class => $table) {
            if ($this->isScoped($class) && ! Schema::hasColumn($table, 'store_id')) {
                $missing[] = "{$class} ({$table})";
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            'These models carry the store scope but their tables have no `store_id`. '
                .'Every query they make names a column that is not there — the failure #958 was.',
        );
    }

    /**
     * Every model class under app/Models, mapped to its table.
     *
     * Resolved through the model rather than guessed from the class name: three
     * of these tables are named nothing like their model (`collections`,
     * `product_rating`, `groups`).
     *
     * @return array<class-string<Model>, string>
     */
    private function models(): array
    {
        $tables = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            try {
                $model = new $class;
            } catch (Throwable) {
                // A model that cannot be constructed without arguments is not
                // something this rule can speak about.
                continue;
            }

            $table = $model->getTable();

            if (Schema::hasTable($table)) {
                $tables[$class] = $table;
            }
        }

        return $tables;
    }

    private function isScoped(string $class): bool
    {
        return in_array(IsStoreScoped::class, class_uses_recursive($class), true);
    }
}
