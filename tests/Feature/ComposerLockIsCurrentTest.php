<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `composer.lock` agrees with `composer.json`.
 *
 * Composer stores a hash of the dependency-relevant part of `composer.json` in
 * the lock, and warns on every `composer install` when the two disagree. The
 * warning is easy to miss and easy to live with, so the disagreement survives
 * until somebody runs `composer update` for an unrelated reason and finds a
 * pending change they did not make.
 *
 * It matters more here than in most repositories: `composer.json` is regularly
 * edited where Composer cannot be run — a rename, a script, a constraint — so
 * the lock is updated by hand or not at all. This is the check that says which.
 *
 * The hash is Composer's own: the relevant keys, sorted, JSON-encoded, md5'd.
 * If a future Composer changes that list, this fails against a lock Composer
 * itself wrote, and the list below is what to update.
 */
class ComposerLockIsCurrentTest extends TestCase
{
    /**
     * `Composer\Package\Locker::getContentHash`. Deliberately not everything in
     * the file — `description`, `scripts` and `autoload` are absent because
     * changing them cannot change what gets installed.
     *
     * @var list<string>
     */
    private const RELEVANT = [
        'name', 'version', 'require', 'require-dev', 'conflict', 'replace',
        'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra',
    ];

    public function test_the_lock_was_written_for_this_composer_json(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);
        $lock = json_decode(file_get_contents(base_path('composer.lock')), true);

        $this->assertNotNull($composer, 'composer.json is not valid JSON.');
        $this->assertNotNull($lock, 'composer.lock is not valid JSON.');

        $this->assertSame(
            $this->contentHash($composer),
            $lock['content-hash'] ?? null,
            'composer.lock was written for a different composer.json. Run `composer update --lock`, '
                .'which rewrites the hash without changing a single installed version.',
        );
    }

    /**
     * @param  array<string, mixed>  $composer
     */
    private function contentHash(array $composer): string
    {
        $relevant = [];

        foreach (array_intersect(self::RELEVANT, array_keys($composer)) as $key) {
            $relevant[$key] = $composer[$key];
        }

        if (isset($composer['config']['platform'])) {
            $relevant['config']['platform'] = $composer['config']['platform'];
        }

        ksort($relevant);

        return md5(json_encode($relevant));
    }
}
