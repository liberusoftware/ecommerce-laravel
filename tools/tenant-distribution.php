<?php

/**
 * The #944 tenant-distribution checklist, without Laravel.
 *
 *     php tools/tenant-distribution.php
 *
 * Runs from a checkout with **no `vendor/`, no Composer install and no
 * deploy** — it needs PHP with PDO and a database it can reach, which is the
 * difference between an operator running this today and it waiting on a
 * release. `php artisan tenants:distribution` is the same report on a machine
 * that does have the application; both call the same class, because two
 * implementations would drift and the one nobody ran would be the one somebody
 * trusted.
 *
 * Connection details come from `.env` in the repository root, and any of them
 * can be overridden by a real environment variable of the same name — which is
 * how you point it at a read replica, or at a restored copy rather than at
 * production itself:
 *
 *     DB_HOST=replica.internal php tools/tenant-distribution.php
 *
 * **It writes nothing.** Only SELECTs, and the connection can be a read-only
 * user.
 *
 * @see TenantDistributionReport
 */
require __DIR__.'/../app/Support/TenantDistributionReport.php';

use App\Support\TenantDistributionReport;

$root = dirname(__DIR__);

/**
 * Enough `.env` parsing for connection details, and no more. A real
 * environment variable wins, so the file is the default rather than the law.
 */
$config = static function (string $key, string $default = '') use ($root): string {
    $fromEnvironment = getenv($key);

    if ($fromEnvironment !== false && $fromEnvironment !== '') {
        return $fromEnvironment;
    }

    static $file;

    if ($file === null) {
        $file = [];

        foreach (@file($root.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $file[trim($name)] = trim(trim($value), "\"'");
        }
    }

    return $file[$key] ?? $default;
};

$driver = $config('DB_CONNECTION', 'mysql');
$database = $config('DB_DATABASE');

$dsn = match ($driver) {
    'sqlite' => 'sqlite:'.($database === ':memory:' ? $database : (str_starts_with($database, '/') ? $database : $root.'/'.$database)),
    'pgsql' => "pgsql:host={$config('DB_HOST', '127.0.0.1')};port={$config('DB_PORT', '5432')};dbname={$database}",
    default => "mysql:host={$config('DB_HOST', '127.0.0.1')};port={$config('DB_PORT', '3306')};dbname={$database};charset=utf8mb4",
};

try {
    $pdo = new PDO($dsn, $config('DB_USERNAME'), $config('DB_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $exception) {
    fwrite(STDERR, implode("\n", [
        'Could not connect: '.$exception->getMessage(),
        '',
        "Read {$root}/.env for DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD.",
        'Any of them can be overridden by an environment variable of the same name.',
        '',
    ])."\n");

    exit(1);
}

echo (new TenantDistributionReport($pdo))->markdown(
    $config('APP_ENV', 'unknown'),
    $database === '' ? 'unknown' : $database,
), "\n";
