<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Every version the README claims is a version `composer.lock` agrees with.
 *
 * [`REPOSITORIES.md` §6.1](https://github.com/liberusoftware/documentation/blob/main/architecture/REPOSITORIES.md)
 * forbids hard-coding a version CI does not verify, and the README hard-codes
 * four of them — in badges and again in prose. A README is the first thing
 * anyone reads and the last thing anyone updates, so the claim outlives the
 * dependency: the badge says Laravel 13 for as long as nobody remembers, which
 * is how a reader ends up debugging against the wrong framework.
 *
 * The plan's wording is *generate the badges from `composer.lock`*. Verifying is
 * what the standard actually asks for, and it is the cheaper half: a generator
 * is a script, a commit step and a way for the two to disagree, to spare a
 * four-character edit that happens once per major upgrade. When this fails, the
 * fix is to type the new number.
 */
class ReadmeVersionsTest extends TestCase
{
    /**
     * Where each claimed name gets its true version from.
     *
     * PHP comes from the `require` constraint rather than the lock's platform
     * block, because the constraint is what the project supports; the lock only
     * records what the machine that resolved it happened to run.
     */
    private const PACKAGES = [
        'Laravel' => 'laravel/framework',
        'Filament' => 'filament/filament',
        'Livewire' => 'livewire/livewire',
    ];

    public function test_every_version_the_readme_claims_matches_composer_lock(): void
    {
        $readme = file_get_contents(base_path('README.md'));
        $actual = $this->lockedVersions();

        // Badges write `Laravel-13`, prose writes `Laravel 13`. Both are the
        // same claim and both go stale the same way, so both are checked —
        // matching only line 15 would leave the badge above it lying.
        preg_match_all(
            '/\b(PHP|Laravel|Filament|Livewire)[- ](\d+(?:\.\d+)*)/',
            $readme,
            $matches,
            PREG_SET_ORDER,
        );

        $this->assertNotEmpty($matches, 'Found no version claims in the README — the pattern is wrong.');

        $wrong = [];

        foreach ($matches as [$claim, $name, $claimed]) {
            if (! $this->isPrefixOf($claimed, $actual[$name])) {
                $wrong[] = "{$claim} — composer.lock says {$name} {$actual[$name]}";
            }
        }

        $wrong = array_values(array_unique($wrong));
        sort($wrong);

        $this->assertSame([], $wrong, implode("\n", [
            'The README claims versions this project does not use:',
            '',
            ...$wrong,
            '',
            'Update the README. A version nobody verifies is a version that goes stale',
            'the first time somebody upgrades and does not scroll up.',
        ]));
    }

    /**
     * @return array<string, string>
     */
    private function lockedVersions(): array
    {
        $lock = json_decode(file_get_contents(base_path('composer.lock')), true);
        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        $versions = ['PHP' => ltrim($composer['require']['php'], '^~>=< ')];

        foreach ($lock['packages'] as $package) {
            $name = array_search($package['name'], self::PACKAGES, true);

            if ($name !== false) {
                $versions[$name] = ltrim($package['version'], 'v');
            }
        }

        $missing = array_diff(['PHP', ...array_keys(self::PACKAGES)], array_keys($versions));

        $this->assertSame([], array_values($missing),
            'These are not in composer.lock, so the README cannot be checked against it: '.implode(', ', $missing));

        return $versions;
    }

    /**
     * Segment by segment, not `str_starts_with` — `1` is a string prefix of
     * `13.23.0` and is not a truthful claim about it.
     */
    private function isPrefixOf(string $claimed, string $actual): bool
    {
        $claimedParts = explode('.', $claimed);
        $actualParts = explode('.', $actual);

        return array_slice($actualParts, 0, count($claimedParts)) === $claimedParts;
    }
}
