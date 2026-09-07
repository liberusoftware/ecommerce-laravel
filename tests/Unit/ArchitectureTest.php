<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Static rules about the shipped code, checked by reading it rather than
 * running it.
 *
 * These use token_get_all rather than grep. A regex over the raw text cannot
 * tell `env('X')` from the same characters inside a string literal, a comment,
 * or a docblock example — and the codebase has all three. Tokenising asks PHP
 * itself what is code, so the rules have no false positives to explain away and
 * nobody learns to ignore them.
 */
class ArchitectureTest extends TestCase
{
    /** Directories whose contents ship and run in production. */
    private const SCANNED = ['app', 'routes', 'database', 'bootstrap'];

    public function test_env_is_only_called_from_config(): void
    {
        $found = $this->callSites(['env']);

        $this->assertSame([], $found, implode("\n", [
            'env() must only be called from config/.',
            '',
            'Under `config:cache` — which is how this deploys — env() returns null',
            'everywhere except while config files are being loaded. A call outside',
            'config/ therefore works locally and silently resolves to nothing in',
            'production. DropxlService and SubscriptionController both shipped that',
            'way. Read the value through config() and add the config/ entry.',
            '',
            ...$found,
        ]));
    }

    public function test_no_debug_helpers_are_left_in_shipped_code(): void
    {
        $found = $this->callSites(['dd', 'dump', 'var_dump', 'ray', 'print_r']);

        $this->assertSame([], $found, "Debug helpers left in shipped code:\n".implode("\n", $found));
    }

    /**
     * Every call to one of $names, as file:line. Method calls and definitions of
     * the same name are not call sites of the global function and are skipped.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function callSites(array $names): array
    {
        $found = [];

        foreach ($this->phpFiles() as $file) {
            $tokens = token_get_all(file_get_contents($file->getPathname()));

            foreach ($tokens as $i => $token) {
                if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], $names, true)) {
                    continue;
                }

                // `$this->env(...)`, `Foo::env(...)`, `function env(...)` — the
                // name matches but none of them calls the global function.
                $before = $this->significantToken($tokens, $i, -1);
                if (is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                    continue;
                }

                if ($this->significantToken($tokens, $i, 1) !== '(') {
                    continue;
                }

                $found[] = $this->relativePath($file).':'.$token[2];
            }
        }

        return $found;
    }

    /** The next non-whitespace, non-comment token in $direction from $i. */
    private function significantToken(array $tokens, int $i, int $direction): array|string|null
    {
        for ($j = $i + $direction; isset($tokens[$j]); $j += $direction) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$j];
        }

        return null;
    }

    /** @return iterable<SplFileInfo> */
    private function phpFiles(): iterable
    {
        foreach (self::SCANNED as $directory) {
            $path = $this->basePath().DIRECTORY_SEPARATOR.$directory;

            if (! is_dir($path)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    yield $file;
                }
            }
        }
    }

    private function relativePath(SplFileInfo $file): string
    {
        return ltrim(str_replace($this->basePath(), '', $file->getPathname()), DIRECTORY_SEPARATOR);
    }

    /**
     * The repository root. Resolved from __DIR__ rather than base_path() so this
     * test needs no booted application — it reads files, nothing else.
     */
    private function basePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
