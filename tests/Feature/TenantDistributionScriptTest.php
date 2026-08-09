<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * `tools/tenant-distribution.php` runs without the framework.
 *
 * That is the only thing worth testing about it, and it cannot be tested in
 * process: the whole point of the file is that it works on a machine with no
 * `vendor/`, and a test that called its code through the application would
 * prove the opposite of what it claims. So it runs as a subprocess, against a
 * throwaway SQLite file, with the connection passed as real environment
 * variables — which is also the documented way to point it at a replica.
 *
 * The counting is `TenantDistributionReport`'s and is covered against the real
 * schema by `TenantDistributionCommandTest`. What is covered here is the part
 * only this file has: finding its own class without an autoloader, reading the
 * connection, and building a DSN.
 */
class TenantDistributionScriptTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = tempnam(sys_get_temp_dir(), 'tenants').'.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->database);

        parent::tearDown();
    }

    public function test_it_reports_without_an_autoloader_or_an_application(): void
    {
        $this->seed_a_database_with_one_row_across_a_boundary();

        $process = new Process(
            [PHP_BINARY, base_path('tools/tenant-distribution.php')],
            base_path(),
            [
                'APP_ENV' => 'probe',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $this->database,
            ],
        );

        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        $output = $process->getOutput();

        $this->assertStringContainsString('teams: 2, personal: 1', $output);
        $this->assertStringContainsString('One row is already attributed across a tenancy boundary', $output);
        $this->assertStringContainsString('| wishlists | product_id → products | 1 |', $output);

        // The null row is counted apart from the attributed ones, which is the
        // distinction wave 2's backfill turns on.
        $this->assertStringContainsString('| wishlists | NULL | 1 |', $output);
    }

    public function test_it_says_what_to_look_at_when_it_cannot_connect(): void
    {
        $process = new Process(
            [PHP_BINARY, base_path('tools/tenant-distribution.php')],
            base_path(),
            ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/nonexistent/directory/db.sqlite'],
        );

        $process->run();

        // An operator running this against production has no stack trace to
        // read and nobody to ask, so the failure names the keys it read.
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Could not connect', $process->getErrorOutput());
        $this->assertStringContainsString('DB_DATABASE', $process->getErrorOutput());
    }

    private function seed_a_database_with_one_row_across_a_boundary(): void
    {
        $pdo = new \PDO('sqlite:'.$this->database, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $pdo->exec('CREATE TABLE teams (id INTEGER PRIMARY KEY, user_id INT, name TEXT, personal_team INT, created_at TEXT)');
        $pdo->exec('CREATE TABLE team_user (id INTEGER PRIMARY KEY, team_id INT, user_id INT)');
        $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, team_id INT)');
        $pdo->exec('CREATE TABLE wishlists (id INTEGER PRIMARY KEY, user_id INT, product_id INT, team_id INT)');

        $pdo->exec('INSERT INTO teams VALUES (1, 10, "Acme", 1, "2026-01-01"), (2, 20, "Other", 0, "2026-01-02")');
        $pdo->exec('INSERT INTO products VALUES (1, 2)');

        // Owned by team 1, pointing at team 2's product. The user owns team 1,
        // so the membership check stays quiet and this is the only breach.
        $pdo->exec('INSERT INTO wishlists VALUES (1, 10, 1, 1)');
        $pdo->exec('INSERT INTO wishlists VALUES (2, 10, NULL, NULL)');
    }
}
