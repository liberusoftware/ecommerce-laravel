<?php

namespace App\Console\Commands;

use App\Support\TenantDistributionReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The [#944](https://github.com/liberusoftware/ecommerce-laravel/issues/944)
 * checklist, for a machine that has the application.
 *
 * The report itself is `TenantDistributionReport`, which needs a `PDO` and
 * nothing else — because the machine that can reach production is not always
 * the one with a working `vendor/`. `tools/tenant-distribution.php` is the
 * same report without Laravel. **Neither writes anything.**
 */
class TenantDistribution extends Command
{
    protected $signature = 'tenants:distribution';

    protected $description = 'Read-only: tenant counts, per-table distribution and cross-boundary mismatches for #944';

    public function handle(): int
    {
        $connection = DB::connection();

        $report = (new TenantDistributionReport($connection->getPdo()))->markdown(
            (string) config('app.env'),
            (string) $connection->getDatabaseName(),
        );

        // Line by line rather than as one string: the console writes it the
        // same either way, but a single write is a single output chunk, and
        // anything reading this output a line at a time — a test expectation, a
        // `grep` in a pipeline — sees one blob instead.
        foreach (explode("\n", $report) as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }
}
