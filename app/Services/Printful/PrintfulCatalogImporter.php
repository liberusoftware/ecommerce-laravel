<?php

namespace App\Services\Printful;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Services\ProductVariantService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Mirrors the configured Printful store's sync products as local products with variants.
 * Products are made to order, so nothing here is stocked; Printful's retail price is the shop's price.
 */
class PrintfulCatalogImporter
{
    public const SUPPLIER = 'printful';

    private array $supplier;

    public function __construct()
    {
        $this->supplier = config('dropshipping.suppliers.printful', []);
    }

    public function isConfigured(): bool
    {
        return filled(preg_replace('/^Bearer\s*/i', '', (string) ($this->supplier['auth']['key'] ?? '')))
            && filled($this->supplier['endpoints']['sync_products'] ?? null);
    }

    /** @return list<array> the store's sync products, as Printful lists them */
    public function listProducts(): array
    {
        $products = [];
        $offset = 0;

        do {
            $page = $this->request()->get($this->supplier['endpoints']['sync_products'], ['offset' => $offset, 'limit' => 100])->throw()->json('result', []);
            $products = [...$products, ...$page];
            $offset += count($page);
        } while (count($page) === 100);

        return $products;
    }

    /** @return array{imported: int, failed: int} */
    public function importAll(): array
    {
        $imported = $failed = 0;

        foreach ($this->listProducts() as $summary) {
            try {
                $this->importProduct($summary['id']);
                $imported++;
            } catch (Throwable $e) {
                Log::error('Printful product import failed', ['sync_product_id' => $summary['id'] ?? null, 'exception' => $e]);
                $failed++;
            }
        }

        return compact('imported', 'failed');
    }

    /** Import one sync product, onto an existing product when one is given. */
    public function importProduct(int|string $syncProductId, ?Product $onto = null): Product
    {
        $detail = $this->request()->get($this->supplier['endpoints']['sync_products'].'/'.rawurlencode((string) $syncProductId))->throw()->json('result', []);
        $syncProduct = Arr::get($detail, 'sync_product', []);
        $variants = collect(Arr::get($detail, 'sync_variants', []))->reject(fn ($v) => blank($v['id'] ?? null))->values();
        $name = $syncProduct['name'] ?? "Printful product {$syncProductId}";

        $product = $onto ?? Product::firstOrNew(['supplier_id' => self::SUPPLIER, 'supplier_product_id' => (string) $syncProductId]);
        $product->fill([
            'supplier_id' => self::SUPPLIER,
            'supplier_product_id' => (string) $syncProductId,
            'track_inventory' => false,
        ]);
        if (! $product->exists) {
            $product->fill([
                'name' => $name,
                'slug' => $this->freeSlug($name),
                'price' => $variants->pluck('retail_price')->filter(fn ($p) => is_numeric($p))->min() ?? 0,
                'category_id' => $this->categoryFor($name, $variants->all()),
                'inventory_count' => 0,
            ]);
        }
        $product->featured_image = $product->featured_image ?: ($syncProduct['thumbnail_url'] ?? null);
        $product->save();

        $this->syncVariants($product, $variants, $name);
        $this->syncGallery($product, $variants);

        return $product->fresh();
    }

    /**
     * The first category in `dropshipping.suppliers.printful.categories` whose keywords appear in the
     * product's or its variants' names. Without rules, imports are left uncategorised.
     */
    protected function categoryFor(string $name, array $variants): ?int
    {
        $text = Str::lower($name.' '.collect($variants)->pluck('name')->implode(' '));

        foreach ($this->supplier['categories'] ?? [] as $category => $rule) {
            if (Str::contains($text, array_map('strtolower', $rule['keywords'] ?? [])) || ($rule['default'] ?? false)) {
                return ProductCategory::firstOrCreate(['slug' => Str::slug($category)], ['name' => $category, 'description' => $rule['description'] ?? null])->id;
            }
        }

        return null;
    }

    /** @param  Collection<int, array>  $variants */
    private function syncVariants(Product $product, Collection $variants, string $syncName): void
    {
        if ($variants->isEmpty()) {
            return;
        }

        $parsed = $variants->map(fn (array $v) => ['variant' => $v] + $this->options($v, $syncName));
        $axes = collect(['Colour' => 'colour', 'Size' => 'size'])->filter(fn ($key) => $parsed->pluck($key)->filter()->isNotEmpty());
        if ($axes->isEmpty()) {
            $axes = collect(['Style' => 'name']);
            $parsed = $parsed->map(fn ($p) => $p + ['name' => $p['variant']['name'] ?? (string) $p['variant']['id']]);
        }

        $current = $product->variants()->get();
        $rows = $parsed->map(function (array $p) use ($axes, $current) {
            $values = $axes->map(fn ($key, $name) => ['name' => $name, 'value' => (string) ($p[$key] ?: 'Standard')])->values()->all();

            return [
                // A re-import keeps each variant by the supplier's id; otherwise the service matches by combination.
                'id' => $current->firstWhere('supplier_variant_id', (string) $p['variant']['id'])?->id,
                'attribute_values' => $values,
                'sku' => $p['variant']['sku'] ?? $p['variant']['external_id'] ?? null,
                'price' => (float) ($p['variant']['retail_price'] ?? 0),
                'track_inventory' => false,
                'inventory_count' => 0,
                'is_active' => true,
            ];
        });

        $attributes = $axes->keys()->map(fn ($name) => [
            'name' => $name,
            'values' => $rows->pluck('attribute_values')->flatten(1)->where('name', $name)->pluck('value')->unique()->values()->all(),
        ])->all();

        app(ProductVariantService::class)->sync($product, $attributes, $rows->all());

        $byLabel = $product->variants()->get()->keyBy(fn (ProductVariant $v) => $v->label());
        foreach ($rows as $i => $row) {
            $variant = $byLabel->get(ProductVariant::labelFor($row['attribute_values']))
                ?? throw new RuntimeException("Printful variant {$parsed[$i]['variant']['id']} was not kept.");
            $variant->update(['supplier_variant_id' => (string) $parsed[$i]['variant']['id']]);
        }
    }

    /** Printful's mockups of the product, added to its gallery once each. */
    private function syncGallery(Product $product, Collection $variants): void
    {
        $have = $product->images()->pluck('image');
        $order = (int) $product->images()->max('order');

        $variants->flatMap(fn ($v) => $v['files'] ?? [])
            ->filter(fn ($file) => ($file['type'] ?? null) === 'preview' && filled($file['preview_url'] ?? null))
            ->pluck('preview_url')->unique()->diff($have)
            ->each(function (string $url) use ($product, &$order) {
                $product->images()->create(['image' => $url, 'order' => ++$order]);
            });
    }

    /** @return array{colour: ?string, size: ?string} from Printful's fields, its named options, or the variant's name */
    private function options(array $variant, string $productName): array
    {
        if (filled($variant['color'] ?? null) || filled($variant['size'] ?? null)) {
            return ['colour' => ($variant['color'] ?? null) ?: null, 'size' => ($variant['size'] ?? null) ?: null];
        }

        $options = collect($variant['options'] ?? [])->mapWithKeys(fn ($o) => [Str::lower((string) ($o['name'] ?? $o['id'] ?? '')) => $o['value'] ?? null]);
        $colour = $options->first(fn ($value, $key) => Str::contains($key, ['color', 'colour']));
        $size = $options->first(fn ($value, $key) => Str::contains($key, 'size'));

        if (! $colour && ! $size) {
            // "Unisex Tee / Black / M" or "Black / M": a leading product name is dropped, then colour and size.
            $parts = array_map('trim', explode('/', (string) ($variant['name'] ?? '')));
            if (count($parts) > 1 && Str::startsWith(Str::lower($productName), Str::lower($parts[0]))) {
                array_shift($parts);
            }
            [$colour, $size] = match (count($parts)) {
                0 => [null, null],
                1 => [null, $parts[0]],
                default => [$parts[count($parts) - 2], $parts[count($parts) - 1]],
            };
        }

        return ['colour' => $colour ?: null, 'size' => $size ?: null];
    }

    private function freeSlug(string $name): string
    {
        $slug = $base = Str::slug($name) ?: 'printful-product';
        for ($i = 2; Product::withoutGlobalScopes()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function request(): PendingRequest
    {
        $headers = ['Accept' => 'application/json', 'Authorization' => $this->supplier['auth']['key'] ?? ''];
        if (filled($this->supplier['store_id'] ?? null)) {
            $headers['X-PF-Store-Id'] = (string) $this->supplier['store_id'];
        }

        return Http::withHeaders($headers)->timeout(30);
    }
}
