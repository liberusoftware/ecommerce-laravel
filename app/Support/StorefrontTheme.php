<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\View\FileViewFinder;

/**
 * The storefront theme for this request: `themes/<name>/` views win over the
 * core's, following `parent` in its theme.json, and anything a theme does not
 * ship falls back to resources/views. See docs/THEMES.md.
 */
class StorefrontTheme
{
    /** The core's own storefront: no directory needed. */
    public const DEFAULT = 'theme-ecommerce';

    private const CORE_ENTRIES = ['resources/css/app.css', 'resources/js/app.js'];

    /** @var list<string>|null */
    private ?array $corePaths = null;

    /** @var list<string> most specific first */
    private array $chain = [];

    /** @var array<string, list<string>> vendor namespace => theme directories this request added */
    private array $vendorPaths = [];

    public function __construct(
        private readonly FileViewFinder $finder,
        private readonly string $root,
    ) {}

    public function apply(?string $name): void
    {
        $this->corePaths ??= $this->finder->getPaths();
        $this->chain = $name === null ? [] : $this->chain($name);

        $themePaths = array_filter(
            array_map(fn (string $theme) => "{$this->root}/{$theme}/resources/views", $this->chain),
            'is_dir',
        );

        $this->finder->setPaths([...$themePaths, ...$this->corePaths]);
        $this->applyVendorOverrides();
        $this->finder->flush();
    }

    /** themes/<name>/resources/views/vendor/<namespace>/ overrides `<namespace>::` views, as resources/views/vendor does. */
    private function applyVendorOverrides(): void
    {
        $hints = $this->finder->getHints();

        foreach ($this->vendorPaths as $namespace => $dirs) {
            $this->finder->replaceNamespace($namespace, array_values(array_diff($hints[$namespace] ?? [], $dirs)));
        }
        $this->vendorPaths = [];

        foreach (array_reverse($this->chain) as $theme) {
            foreach (glob("{$this->root}/{$theme}/resources/views/vendor/*", GLOB_ONLYDIR) ?: [] as $dir) {
                $this->finder->prependNamespace(basename($dir), $dir);
                $this->vendorPaths[basename($dir)][] = $dir;
            }
        }
    }

    public function name(): string
    {
        return $this->chain[0] ?? self::DEFAULT;
    }

    /** @return list<string> Vite entry points: the nearest theme that declares assets, else the core's. */
    public function entries(): array
    {
        foreach ($this->chain as $theme) {
            $assets = $this->manifest($theme)['assets'] ?? [];
            $entries = [...($assets['css'] ?? []), ...($assets['js'] ?? [])];

            if ($entries !== []) {
                return array_map(fn (string $path) => "themes/{$theme}/".ltrim($path, '/'), $entries);
            }
        }

        return self::CORE_ENTRIES;
    }

    /** @return list<string> */
    public function chain(string $name): array
    {
        $chain = [];

        while ($name !== null && ! in_array($name, $chain, true)) {
            $manifest = $this->manifest($name);

            if ($manifest === null) {
                if ($name !== self::DEFAULT) {
                    Log::warning('Storefront theme not found; using its parent or the core views.', ['theme' => $name]);
                }
                break;
            }

            $chain[] = $name;
            $name = $manifest['parent'] ?? null;
        }

        return $chain;
    }

    /** @return array<string, mixed>|null */
    public function manifest(string $name): ?array
    {
        // A channel names its theme; never let that name walk out of themes/.
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $name)) {
            return null;
        }

        $file = "{$this->root}/{$name}/theme.json";
        $manifest = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($manifest) ? $manifest : null;
    }
}
