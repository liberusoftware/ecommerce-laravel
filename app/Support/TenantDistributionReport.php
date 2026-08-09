<?php

namespace App\Support;

use PDO;

/**
 * The [#944](https://github.com/liberusoftware/ecommerce-laravel/issues/944)
 * checklist, computed from a `PDO` and nothing else.
 *
 * **Framework-free on purpose.** The checklist has to run against production and
 * staging, and the machine that can reach those databases is not always the one
 * with a working `vendor/`. Everything here uses `PDO` and the schema, so
 * `tools/tenant-distribution.php` can `require` this file directly with no
 * autoloader, no Composer install and no deploy — while `php artisan
 * tenants:distribution` runs the same code on a machine that does have the
 * application.
 *
 * One implementation, two entry points. Two implementations would drift, and
 * the one nobody ran would be the one somebody trusted.
 *
 * It reads the schema rather than a hard-coded list of tables, so a table that
 * gains `team_id` is covered without anybody remembering. **It writes nothing.**
 */
class TenantDistributionReport
{
    /**
     * `team_id` on these is the membership graph itself rather than tenant data.
     */
    private const NOT_TENANT_DATA = ['teams', 'team_user', 'team_invitations'];

    /**
     * Foreign keys whose parent also carries `team_id`, so the two can be
     * compared. A disagreement is not a suspicion — it is a row already sitting
     * on the wrong side of a tenancy boundary.
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

    public function __construct(private readonly PDO $pdo) {}

    public function markdown(string $environment, string $database): string
    {
        $tables = $this->tenantTables();

        return implode("\n", [
            "# Tenant distribution — {$environment} — {$database}",
            '',
            'Produced by the #944 report (reads only).',
            '',
            $this->tenants(),
            $this->distribution($tables),
            $this->mismatches($tables),
        ]);
    }

    /**
     * §1 — how many tenants actually exist.
     *
     * One non-personal team means the boundary has a single occupant today and
     * the backfill is trivial. Several means the quarantine rule is carrying
     * real weight.
     */
    private function tenants(): string
    {
        $teams = $this->rows('SELECT id, name, personal_team, created_at FROM teams ORDER BY id');
        $personal = count(array_filter($teams, fn ($team) => (int) $team['personal_team'] === 1));

        return implode("\n", [
            '## 1. Tenants',
            '',
            'teams: '.count($teams).', personal: '.$personal,
            '',
            $this->table(['id', 'name', 'personal_team', 'created_at'], array_map(fn ($team) => [
                $team['id'], $team['name'], ((int) $team['personal_team'] === 1) ? 'yes' : 'no', (string) $team['created_at'],
            ], $teams)),
        ]);
    }

    /**
     * §2 — distribution per table, nulls as their own row.
     *
     * Belonging to nobody is a different state from belonging to team 1, and
     * the two must not be summed.
     *
     * @param  list<string>  $tables
     */
    private function distribution(array $tables): string
    {
        $rows = [];

        foreach ($tables as $table) {
            $counts = $this->rows(
                "SELECT team_id, COUNT(*) AS rows_count FROM {$this->quote($table)} GROUP BY team_id ORDER BY team_id"
            );

            foreach ($counts as $count) {
                $rows[] = [$table, $count['team_id'] ?? 'NULL', $count['rows_count']];
            }

            if ($counts === []) {
                $rows[] = [$table, '—', 0];
            }
        }

        return implode("\n", ['## 2. Rows per tenant, per table', '', $this->table(['table', 'team_id', 'rows'], $rows)]);
    }

    /**
     * §3 — how much of this is already wrong.
     *
     * Two kinds of evidence, both conclusive rather than indicative: a row whose
     * parent belongs to a different team, and a row on a team whose named user
     * is neither a member of it nor its owner.
     *
     * @param  list<string>  $tables
     */
    private function mismatches(array $tables): string
    {
        $rows = [];

        foreach ($tables as $table) {
            $columns = $this->columns($table);

            foreach (self::OWNED_BY as $column => $parent) {
                if (! in_array($column, $columns, true) || ! $this->isTenantScoped($parent)) {
                    continue;
                }

                $rows[] = [$table, "{$column} → {$parent}", $this->count(
                    'SELECT COUNT(*) FROM '.$this->quote($table).' c '.
                    'JOIN '.$this->quote($parent).' p ON p.id = c.'.$column.' '.
                    'WHERE c.team_id IS NOT NULL AND p.team_id IS NOT NULL AND c.team_id <> p.team_id'
                )];
            }

            if (in_array('user_id', $columns, true)) {
                $rows[] = [$table, 'user_id not in team', $this->count(
                    'SELECT COUNT(*) FROM '.$this->quote($table).' c '.
                    'LEFT JOIN team_user tu ON tu.user_id = c.user_id AND tu.team_id = c.team_id '.
                    // A team's owner has no row in its own pivot, so without
                    // this every row the merchant created themselves reads as a
                    // breach — on a real deployment, most of them.
                    'LEFT JOIN teams owned ON owned.id = c.team_id AND owned.user_id = c.user_id '.
                    'WHERE c.team_id IS NOT NULL AND c.user_id IS NOT NULL '.
                    'AND tu.user_id IS NULL AND owned.id IS NULL'
                )];
            }
        }

        $total = array_sum(array_column($rows, 2));

        return implode("\n", [
            '## 3. Rows already across a boundary',
            '',
            match (true) {
                $total === 0 => 'None found.',
                $total === 1 => '**One row is already attributed across a tenancy boundary.** It is not an ambiguous row; it is a wrong one.',
                default => "**{$total} rows are already attributed across a tenancy boundary.** These are not ambiguous rows; they are wrong ones.",
            },
            '',
            $this->table(['table', 'check', 'rows'], $rows),
        ]);
    }

    /**
     * @return list<string>
     */
    private function tenantTables(): array
    {
        $tables = array_filter(
            $this->tables(),
            fn (string $table) => ! in_array($table, self::NOT_TENANT_DATA, true) && $this->isTenantScoped($table),
        );

        sort($tables);

        return array_values($tables);
    }

    private function isTenantScoped(string $table): bool
    {
        return in_array('team_id', $this->columns($table), true);
    }

    /**
     * Schema introspection, which is the one thing PDO does not standardise.
     *
     * @return list<string>
     */
    private function tables(): array
    {
        $sql = $this->driver() === 'sqlite'
            ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"
            : 'SELECT table_name AS name FROM information_schema.tables WHERE table_schema = DATABASE()';

        return array_map(fn ($row) => (string) array_values($row)[0], $this->rows($sql));
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        if ($this->driver() === 'sqlite') {
            return array_map(fn ($row) => (string) $row['name'], $this->rows('PRAGMA table_info('.$this->quote($table).')'));
        }

        $statement = $this->pdo->prepare(
            'SELECT column_name AS name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->execute([$table]);

        return array_map(fn ($row) => (string) array_values($row)[0], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    private function driver(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Table names come from the schema rather than from input, so this is
     * quoting for correctness — a table called `order` — not for safety.
     *
     * MySQL quotes identifiers with backticks unless `ANSI_QUOTES` is set, and
     * a deployment's SQL mode is not this report's business to change.
     */
    private function quote(string $identifier): string
    {
        return $this->driver() === 'mysql'
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * @param  list<string>  $headings
     * @param  list<array<int, string|int|null>>  $rows
     */
    private function table(array $headings, array $rows): string
    {
        $lines = [
            '| '.implode(' | ', $headings).' |',
            '| '.implode(' | ', array_fill(0, count($headings), '---')).' |',
        ];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', $row).' |';
        }

        return implode("\n", $lines)."\n";
    }
}
