<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The [#944](https://github.com/liberusoftware/ecommerce-laravel/issues/944)
 * checklist, as one read-only command.
 *
 * The checklist gates wave 2's backfill and cannot be answered from the
 * repository: a fresh checkout has no `.env` and no database. It can only be
 * answered against each real environment, which meant an operator adapting
 * roughly forty statements per environment by hand — and the numbers decide
 * whether rows get attributed or quarantined, so a transcription slip is a
 * mis-attributed merchant.
 *
 * So the queries are written once, here, and read the schema rather than a
 * hand-kept list of tables. **It writes nothing.** Output is Markdown, because
 * the answer is pasted onto the issue.
 */
class TenantDistribution extends Command
{
    protected $signature = 'tenants:distribution';

    protected $description = 'Read-only: tenant counts, per-table distribution and cross-boundary mismatches for #944';

    /**
     * `team_id` on these is the membership graph itself rather than tenant data
     * — the same exclusion `StoreBackfillTest` makes, for the same reason.
     */
    private const NOT_TENANT_DATA = ['teams', 'team_user', 'team_invitations'];

    /**
     * Foreign keys whose parent also carries `team_id`, so parent and child can
     * be compared. A disagreement is not a suspicion: it is a row already
     * sitting on the wrong side of a tenancy boundary.
     *
     * @var array<string, string>
     */
    private const OWNED_BY = [
        'customer_id' => 'customers',
        'order_id' => 'orders',
        'product_id' => 'products',
        'product_category_id' => 'product_categories',
        'collection_id' => 'collections',
        'coupon_id' => 'coupons',
    ];

    public function handle(): int
    {
        $this->line('# Tenant distribution — '.config('app.env').' — '.DB::connection()->getDatabaseName());
        $this->line('');
        $this->line('Produced by `php artisan tenants:distribution` (reads only).');
        $this->line('');

        $tables = $this->tenantTables();

        $this->teams();
        $this->distribution($tables);
        $this->mismatches($tables);

        return self::SUCCESS;
    }

    /**
     * §1 — how many tenants actually exist.
     *
     * One non-personal team means the boundary has a single occupant today and
     * the backfill is trivial. Several means the quarantine rule is carrying
     * real weight.
     */
    private function teams(): void
    {
        $teams = DB::table('teams')->orderBy('id')->get(['id', 'name', 'personal_team', 'created_at']);

        $this->line('## 1. Tenants');
        $this->line('');
        $this->line('teams: '.$teams->count().', personal: '.$teams->where('personal_team', true)->count());
        $this->line('');
        $this->markdownTable(['id', 'name', 'personal_team', 'created_at'], $teams->map(fn ($team) => [
            $team->id, $team->name, $team->personal_team ? 'yes' : 'no', (string) $team->created_at,
        ])->all());
    }

    /**
     * §2 — distribution per table, nulls included as their own row.
     *
     * A null `team_id` belongs to nobody, which is a different state from
     * belonging to team 1, and the two must not be summed.
     *
     * @param  list<string>  $tables
     */
    private function distribution(array $tables): void
    {
        $rows = [];

        foreach ($tables as $table) {
            $counts = DB::table($table)
                ->select('team_id', DB::raw('COUNT(*) AS rows_count'))
                ->groupBy('team_id')
                ->orderBy('team_id')
                ->get();

            foreach ($counts as $count) {
                $rows[] = [$table, $count->team_id ?? 'NULL', $count->rows_count];
            }

            if ($counts->isEmpty()) {
                $rows[] = [$table, '—', 0];
            }
        }

        $this->line('## 2. Rows per tenant, per table');
        $this->line('');
        $this->markdownTable(['table', 'team_id', 'rows'], $rows);
    }

    /**
     * §3 — how much of this is already wrong.
     *
     * Two kinds of evidence, and both are conclusive rather than indicative:
     *
     * - a row whose parent record belongs to a different team;
     * - a row on a team whose named user is neither a member of that team nor
     *   its owner.
     *
     * The issue asks for these to be reported prominently rather than buried in
     * a count, so a non-zero total says so in as many words.
     *
     * @param  list<string>  $tables
     */
    private function mismatches(array $tables): void
    {
        $rows = [];

        foreach ($tables as $table) {
            $columns = Schema::getColumnListing($table);

            foreach (self::OWNED_BY as $column => $parent) {
                if (! in_array($column, $columns, true) || ! $this->isTenantScoped($parent)) {
                    continue;
                }

                $rows[] = [$table, "{$column} → {$parent}", DB::table($table)
                    ->join($parent, "{$parent}.id", '=', "{$table}.{$column}")
                    ->whereNotNull("{$table}.team_id")
                    ->whereNotNull("{$parent}.team_id")
                    ->whereColumn("{$table}.team_id", '!=', "{$parent}.team_id")
                    ->count()];
            }

            if (in_array('user_id', $columns, true)) {
                $rows[] = [$table, 'user_id not in team', $this->rowsWhoseUserIsNotInTheTeam($table)];
            }
        }

        $total = array_sum(array_column($rows, 2));

        $this->line('## 3. Rows already across a boundary');
        $this->line('');
        $this->line($total === 0
            ? 'None found.'
            : "**{$total} rows are already attributed across a tenancy boundary.** These are not ambiguous rows; they are wrong ones.");
        $this->line('');
        $this->markdownTable(['table', 'check', 'rows'], $rows);
    }

    private function rowsWhoseUserIsNotInTheTeam(string $table): int
    {
        return DB::table($table)
            ->leftJoin('team_user', function ($join) use ($table) {
                $join->on('team_user.user_id', '=', "{$table}.user_id")
                    ->on('team_user.team_id', '=', "{$table}.team_id");
            })
            // A team's owner is not in its own pivot, so without this every row
            // created by the merchant themselves would read as a breach.
            ->leftJoin('teams as owned', function ($join) use ($table) {
                $join->on('owned.id', '=', "{$table}.team_id")
                    ->on('owned.user_id', '=', "{$table}.user_id");
            })
            ->whereNotNull("{$table}.team_id")
            ->whereNotNull("{$table}.user_id")
            ->whereNull('team_user.user_id')
            ->whereNull('owned.id')
            ->count();
    }

    /**
     * @return list<string>
     */
    private function tenantTables(): array
    {
        $tables = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? explode('.', $table)[1] : $table;

            if (! in_array($table, self::NOT_TENANT_DATA, true) && $this->isTenantScoped($table)) {
                $tables[] = $table;
            }
        }

        sort($tables);

        return $tables;
    }

    private function isTenantScoped(string $table): bool
    {
        return Schema::hasTable($table) && Schema::hasColumn($table, 'team_id');
    }

    /**
     * @param  list<string>  $headings
     * @param  list<array<int, string|int|null>>  $rows
     */
    private function markdownTable(array $headings, array $rows): void
    {
        $this->line('| '.implode(' | ', $headings).' |');
        $this->line('| '.implode(' | ', array_fill(0, count($headings), '---')).' |');

        foreach ($rows as $row) {
            $this->line('| '.implode(' | ', $row).' |');
        }

        $this->line('');
    }
}
