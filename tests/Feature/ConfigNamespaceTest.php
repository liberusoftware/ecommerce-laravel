<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every `config('namespace.key')` read must name a namespace that resolves.
 *
 * `config()` returns null for a file that was never published, so a typo or a
 * missing config file is silent: the caller gets null and carries on. That is
 * how `SiteSettingsService` came to call `Cache::remember(null, null, ...)`
 * (#938) — `config('site-settings.cache_key')` had no file behind it, so the
 * cache key was the empty string and the null TTL meant the entry was written
 * forever.
 *
 * Only reads with **no default** are checked. `config('modular.modules_directory',
 * 'app-modules')` is fine whether or not that namespace exists — the default is
 * the contract, and supplying one is the way to opt out of this test.
 *
 * Resolved through the container rather than by looking for `config/<ns>.php`,
 * so a namespace a package merges from its own vendor directory counts as
 * present, which it is.
 */
class ConfigNamespaceTest extends TestCase
{
    private const SCANNED = ['app', 'routes', 'database', 'bootstrap'];

    /**
     * `config('foo.bar')` or `config("foo.bar")`, capturing the namespace and
     * whether a default follows.
     */
    private const CALL = '/config\(\s*[\'"]([a-zA-Z0-9_.-]+)[\'"]\s*(,|\))/';

    public function test_every_config_read_without_a_default_names_a_namespace_that_exists(): void
    {
        $missing = [];

        foreach ($this->phpFiles() as $file) {
            preg_match_all(self::CALL, file_get_contents($file), $matches, PREG_SET_ORDER);

            foreach ($matches as [, $key, $follows]) {
                if ($follows === ',') {
                    continue;
                }

                $namespace = explode('.', $key)[0];

                if (config($namespace) === null) {
                    $missing[] = str_replace(base_path().'/', '', $file)." reads config('{$key}')";
                }
            }
        }

        $this->assertSame([], array_unique($missing), implode("\n", [
            'These reads resolve to null, silently:',
            ...array_unique($missing),
            '',
            'Publish the config file, or pass a default as the second argument.',
        ]));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach (self::SCANNED as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
