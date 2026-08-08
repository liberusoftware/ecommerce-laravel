<?php

namespace Tests\Feature;

use App\Models\AbandonedCart;
use App\Models\ABTest;
use App\Models\ABTestAssignment;
use App\Models\AnalyticsEvent;
use App\Models\CartRecoveryAttempt;
use App\Models\CartRecoveryCampaign;
use App\Models\CustomerGroup;
use App\Models\CustomerMetric;
use App\Models\CustomerSegment;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\GiftRegistry;
use App\Models\GiftRegistryItem;
use App\Models\GiftRegistryPurchase;
use App\Models\InventoryAdjustment;
use App\Models\InventoryItem;
use App\Models\InventoryLevel;
use App\Models\InventoryLocation;
use App\Models\ProductInteraction;
use App\Models\ProductOption;
use App\Models\ProductPerformance;
use App\Models\ProductRecommendation;
use App\Models\ProductTaxonomyValue;
use App\Models\ProductVariant;
use App\Models\RecommendationRule;
use App\Models\SeoSetting;
use App\Models\TaxonomyAttribute;
use App\Models\TaxonomyCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `App\Traits\IsTenantModel` is a `team()` BelongsTo relation and nothing else.
 * A model that uses it therefore promises a `team_id` column — and 28 of the 37
 * models that use it have no such column on their table, so the relation is
 * dead on arrival and any tenant scope added later would raise an unknown-column
 * error rather than isolating anything.
 *
 * It was 31. `Discount`, `Menu` and `MenuItem` left the list because they are
 * the three a tenant-scoped Filament resource actually queries, which is the
 * difference between a dormant wrong claim and a live one — #958. The remaining
 * 28 are dormant: no panel and no scope reads them, so `team()` returns null
 * quietly rather than isolating anything.
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
     * Not a to-do list to be worked top to bottom, and not one question either.
     * A model here is one of two different things, and the fix differs:
     *
     * - independently owned by a merchant — `GiftCard`, `InventoryLocation`,
     *   `CustomerSegment`, `SeoSetting` — which wants the column;
     * - a child of something already owned — `GiftCardTransaction`,
     *   `ProductVariant`, `ProductOption`, `ABTestAssignment` — where the owner
     *   is the parent's and the trait is the thing that is wrong. Copying a
     *   `team_id` onto every child table is the blanket migration that produced
     *   half of this in the first place.
     *
     * Which is which is a per-model judgement nobody has made yet, so the list
     * shrinks as models are judged rather than as columns are added.
     *
     * @var list<class-string<Model>>
     */
    private const MISSING_TEAM_ID = [
        ABTest::class,
        ABTestAssignment::class,
        AbandonedCart::class,
        AnalyticsEvent::class,
        CartRecoveryAttempt::class,
        CartRecoveryCampaign::class,
        CustomerGroup::class,
        CustomerMetric::class,
        CustomerSegment::class,
        GiftCard::class,
        GiftCardTransaction::class,
        GiftRegistry::class,
        GiftRegistryItem::class,
        GiftRegistryPurchase::class,
        InventoryAdjustment::class,
        InventoryItem::class,
        InventoryLevel::class,
        InventoryLocation::class,
        ProductInteraction::class,
        ProductOption::class,
        ProductPerformance::class,
        ProductRecommendation::class,
        ProductTaxonomyValue::class,
        ProductVariant::class,
        RecommendationRule::class,
        SeoSetting::class,
        TaxonomyAttribute::class,
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
