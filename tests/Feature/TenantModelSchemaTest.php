<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `App\Traits\IsTenantModel` is a `team()` BelongsTo relation and nothing else.
 * A model that uses it therefore promises a `team_id` column — and 31 of the 37
 * models that use it have no such column on their table, so the relation is
 * dead on arrival and any tenant scope added later would raise an unknown-column
 * error rather than isolating anything.
 *
 * #958 records two of these, because those two happen to be registered as
 * Filament resources in a tenant-scoped panel. They are not special; they are
 * the two that were noticed.
 *
 * This test is a ratchet. The allow-list below can only shrink: a model may not
 * newly join it, and every entry removed is a table that got its column. It is
 * deliberately a list of names rather than a count, so shrinking it requires
 * naming what was fixed.
 */
class TenantModelSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Models using IsTenantModel whose table has no team_id column today.
     *
     * Not a to-do list to be worked top to bottom. `team_id` is nullable with
     * `default(1)`, so adding the column to a table that already holds rows
     * silently attributes every one of them to team 1 — which is how the
     * existing mess was made. These land with the tenant scope in wave 1.5,
     * where unattributable rows are quarantined rather than assigned.
     *
     * @var list<class-string<Model>>
     */
    private const MISSING_TEAM_ID = [
        \App\Models\ABTest::class,
        \App\Models\ABTestAssignment::class,
        \App\Models\AbandonedCart::class,
        \App\Models\AnalyticsEvent::class,
        \App\Models\CartRecoveryAttempt::class,
        \App\Models\CartRecoveryCampaign::class,
        \App\Models\CustomerGroup::class,
        \App\Models\CustomerMetric::class,
        \App\Models\CustomerSegment::class,
        \App\Models\Discount::class,
        \App\Models\GiftCard::class,
        \App\Models\GiftCardTransaction::class,
        \App\Models\GiftRegistry::class,
        \App\Models\GiftRegistryItem::class,
        \App\Models\GiftRegistryPurchase::class,
        \App\Models\InventoryAdjustment::class,
        \App\Models\InventoryItem::class,
        \App\Models\InventoryLevel::class,
        \App\Models\InventoryLocation::class,
        \App\Models\Menu::class,
        \App\Models\MenuItem::class,
        \App\Models\ProductInteraction::class,
        \App\Models\ProductOption::class,
        \App\Models\ProductPerformance::class,
        \App\Models\ProductRecommendation::class,
        \App\Models\ProductTaxonomyValue::class,
        \App\Models\ProductVariant::class,
        \App\Models\RecommendationRule::class,
        \App\Models\SeoSetting::class,
        \App\Models\TaxonomyAttribute::class,
        \App\Models\TaxonomyCategory::class,
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
