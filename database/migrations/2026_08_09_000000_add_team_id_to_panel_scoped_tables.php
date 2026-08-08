<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The three tables reached by a tenant-scoped Filament resource whose model
 * promises a `team()` relation their schema cannot answer — #958.
 *
 * `IsTenantModel` is a `team()` BelongsTo and nothing else, so a model using it
 * against a table with no `team_id` is a claim of ownership with nothing behind
 * it. Thirty-one models are in that position; these three are the ones a panel
 * actually queries, and a panel query is the difference between a dormant wrong
 * claim and a live one.
 *
 * **No `default(1)`, unlike the two migrations that added `team_id` before it.**
 * A default on a tenant key is what made every row created outside Filament
 * silently team 1, which is the ambiguity wave 2 exists to unpick — adding three
 * more tables to it to save one operator query would be paying the same cost
 * twice. Existing rows keep a null `team_id` and are therefore invisible in a
 * tenant-scoped panel until somebody attributes them. That is the plan's
 * asymmetry applied literally: hiding a row from its owner is recoverable,
 * handing it to the wrong merchant is not.
 */
return new class extends Migration
{
    private const TABLES = ['discounts', 'menus', 'menu_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'team_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();
            });
        }

        $this->attributeExistingRows();
    }

    /**
     * Existing rows go to the only team, when there is exactly one.
     *
     * That is not a default dressed up: on a single-team deployment there is no
     * other merchant the rows could belong to, and the alternative is a panel
     * that shows a merchant none of their own discounts or menus the morning
     * after deploying. The same reasoning `StoreContext::forWrites()` uses for
     * the only store, held to the same bound — add a second team and this goes
     * quiet, leaving the rows unattributed for a human to place.
     */
    private function attributeExistingRows(): void
    {
        $teamIds = DB::table('teams')->orderBy('id')->limit(2)->pluck('id');

        if ($teamIds->count() !== 1) {
            return;
        }

        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'team_id')) {
                DB::table($table)->whereNull('team_id')->update(['team_id' => $teamIds->first()]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'team_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['team_id']);
                $blueprint->dropColumn('team_id');
            });
        }
    }
};
