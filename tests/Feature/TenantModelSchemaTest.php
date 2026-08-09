<?php

namespace Tests\Feature;

use App\Models\ABTest;
use App\Models\CartRecoveryCampaign;
use App\Models\CustomerGroup;
use App\Models\CustomerSegment;
use App\Models\InventoryLocation;
use App\Models\RecommendationRule;
use App\Models\SeoSetting;
use App\Models\TaxonomyCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `App\Traits\IsTenantModel` is a `team()` BelongsTo relation and nothing else.
 * A model that uses it therefore promises a `team_id` column, and eight of the
 * seventeen that use it have no such column on their table — so the relation is
 * dead on arrival and a tenant scope added later would raise an unknown-column
 * error rather than isolating anything.
 *
 * It was 31 of 37, and the list shrank twice for opposite reasons.
 *
 * `Discount`, `Menu` and `MenuItem` got the column, because a tenant-scoped
 * Filament resource queries them — a live wrong claim rather than a dormant one
 * (#958). Twenty more lost the trait, because their owner is their parent's:
 * `ProductVariant` belongs to a product, `GiftCardTransaction` to a gift card,
 * `InventoryLevel` to an inventory item. Copying `team_id` onto every child
 * table is the blanket migration that produced half of this, and a relation to
 * a column that will never exist is a claim, not a plan.
 *
 * What is left are the eight roots: no tenant-owned parent to inherit from, so
 * the column is the only way they could ever be tenanted.
 *
 * This test is a ratchet. The allow-list below can only shrink — a model may
 * not newly join it — and it is a list of names rather than a count, so
 * shrinking it means naming what changed.
 */
class TenantModelSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The roots: merchant-owned, with no tenant-owned parent to inherit an
     * owner from, and no table column to be owned by yet.
     *
     * Still not a to-do list. Nothing reads any of them through a panel or a
     * scope, so adding eight columns today buys eight columns nothing writes
     * and nothing filters. Each one gets its `team_id` when something needs to
     * ask whose it is — which is how `discounts` and `menus` got theirs.
     *
     * @var list<class-string<Model>>
     */
    private const MISSING_TEAM_ID = [
        ABTest::class,
        CartRecoveryCampaign::class,
        CustomerGroup::class,
        CustomerSegment::class,
        InventoryLocation::class,
        RecommendationRule::class,
        SeoSetting::class,
        TaxonomyCategory::class,
    ];

    public function test_tenant_models_have_a_team_id_column(): void
    {
        $unexpected = [];
        $fixed = [];

        foreach ($this->tenantModels() as $class) {
            $table = (new $class)->getTable();

            if (! Schema::hasTable($table)) {
                $unexpected[] = "{$class} -> table {$table} does not exist";

                continue;
            }

            $hasColumn = Schema::hasColumn($table, 'team_id');
            $allowed = in_array($class, self::MISSING_TEAM_ID, true);

            if (! $hasColumn && ! $allowed) {
                $unexpected[] = "{$class} -> {$table} has no team_id column";
            }

            if ($hasColumn && $allowed) {
                $fixed[] = $class;
            }
        }

        $this->assertSame([], $unexpected, implode("\n", [
            'These models use IsTenantModel but their table has no team_id column,',
            'so team() is a relation to nothing and a tenant scope on them would',
            'raise an unknown-column error rather than isolating anything.',
            '',
            'Either drop the trait from the model or add the column with the row',
            'attribution decided rather than defaulted.',
            '',
            ...$unexpected,
        ]));

        $this->assertSame([], $fixed,
            "These now have a team_id column and must be removed from MISSING_TEAM_ID:\n".implode("\n", $fixed));
    }

    /**
     * Every model class under app/Models that uses the trait.
     *
     * Read from the source rather than from a hand-kept list, so a model that
     * adopts the trait is covered without anyone remembering to add it here.
     *
     * @return list<class-string<Model>>
     */
    private function tenantModels(): array
    {
        $models = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            if (! str_contains(file_get_contents($file), 'IsTenantModel')) {
                continue;
            }

            $class = 'App\\Models\\'.basename($file, '.php');

            if (class_exists($class) && is_subclass_of($class, Model::class)) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }
}
