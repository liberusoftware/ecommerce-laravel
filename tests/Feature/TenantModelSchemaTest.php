<?php

namespace Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every model that declares tenancy has somewhere to store it. The list of
 * exceptions is empty, and this keeps it so: the pairing has broken in both
 * directions here, a trait on a table with no column and a column dropped from
 * under a model with the trait.
 */
class TenantModelSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Empty, and meant to stay that way.
     *
     * An entry here is a model that says it belongs to a team and has nowhere
     * to record which. Adding one means writing down why that is acceptable —
     * and since the trait now writes the key on create, the honest answer is
     * usually that the model should not have the trait.
     *
     * @var list<class-string<Model>>
     */
    private const MISSING_TEAM_ID = [];

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
