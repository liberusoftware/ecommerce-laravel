<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DropxlService
{
    protected string $apiUrl;

    protected string $apiKey;

    protected array $headers;

    public function __construct()
    {
        $this->apiUrl = rtrim((string) config('services.dropxl.url'), '/');
        $this->apiKey = (string) config('services.dropxl.key');
        $this->headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];
    }

    /**
     * Search products from the DropXL API.
     */
    public function searchProducts(?string $keyword = null, ?string $categoryId = null, int $page = 1, int $perPage = 20): array
    {
        try {
            $params = ['page' => $page, 'per_page' => $perPage];

            if ($keyword) {
                $params['search'] = $keyword;
            }

            if ($categoryId) {
                $params['category_id'] = $categoryId;
            }

            $response = Http::withHeaders($this->headers)
                ->get($this->apiUrl . '/v1/products', $params);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            return ['success' => false, 'message' => 'Failed to fetch products from DropXL', 'error' => $response->body()];
        } catch (Exception $e) {
            Log::error('DropXL search products failed: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Error fetching products from DropXL', 'error' => $e->getMessage()];
        }
    }

    /**
     * Get product categories from the DropXL API.
     */
    public function getCategories(): array
    {
        try {
            $response = Http::withHeaders($this->headers)
                ->get($this->apiUrl . '/v1/categories');

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            return ['success' => false, 'message' => 'Failed to fetch categories from DropXL', 'error' => $response->body()];
        } catch (Exception $e) {
            Log::error('DropXL get categories failed: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Error fetching categories from DropXL', 'error' => $e->getMessage()];
        }
    }

    /**
     * Import a single product from a DropXL product array into the local database.
     */
    public function importProduct(array $dropxlProduct): array
    {
        try {
            $category = $this->resolveCategory($dropxlProduct['category'] ?? null);

            $slug = Str::slug($dropxlProduct['name'] ?? 'product');

            // Prefer slug-based upsert so re-importing the same product name always
            // updates the existing record rather than creating a duplicate.
            $productData = [
                'name' => $dropxlProduct['name'],
                'description' => $dropxlProduct['description'] ?? null,
                'short_description' => $dropxlProduct['short_description'] ?? null,
                'price' => $dropxlProduct['price'] ?? 0,
                'category_id' => $category?->id,
                'inventory_count' => $dropxlProduct['stock'] ?? $dropxlProduct['inventory'] ?? 0,
                'low_stock_threshold' => 5,
                'featured_image' => $dropxlProduct['image_url'] ?? $dropxlProduct['image'] ?? null,
                'meta_title' => $dropxlProduct['name'],
                'meta_description' => $dropxlProduct['short_description'] ?? substr($dropxlProduct['description'] ?? '', 0, 255),
            ];

            $product = Product::updateOrCreate(['slug' => $slug], $productData);

            return ['success' => true, 'product' => $product];
        } catch (Exception $e) {
            Log::error('DropXL import product failed: ' . $e->getMessage());

            return ['success' => false, 'message' => 'Error importing product: ' . $e->getMessage()];
        }
    }

    /**
     * Import all products from a specific DropXL category into the local database.
     * Optionally filters by keyword.
     */
    public function importByCategory(string $categoryId, ?string $keyword = null): array
    {
        $imported = 0;
        $failed = 0;
        $page = 1;

        do {
            $result = $this->searchProducts($keyword, $categoryId, $page, 50);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'message' => $result['message'],
                    'imported' => $imported,
                    'failed' => $failed,
                ];
            }

            $products = $this->extractProducts($result['data']);

            if (empty($products)) {
                break;
            }

            foreach ($products as $dropxlProduct) {
                $importResult = $this->importProduct($dropxlProduct);
                if ($importResult['success']) {
                    $imported++;
                } else {
                    $failed++;
                }
            }

            $hasMore = isset($result['data']['next_page_url']) && $result['data']['next_page_url'];
            $page++;
        } while ($hasMore);

        return ['success' => true, 'imported' => $imported, 'failed' => $failed];
    }

    /**
     * Import all products from DropXL into the local database.
     * Optionally filters by keyword.
     */
    public function importAll(?string $keyword = null): array
    {
        $imported = 0;
        $failed = 0;
        $page = 1;

        do {
            $result = $this->searchProducts($keyword, null, $page, 50);

            if (! $result['success']) {
                return [
                    'success' => false,
                    'message' => $result['message'],
                    'imported' => $imported,
                    'failed' => $failed,
                ];
            }

            $products = $this->extractProducts($result['data']);

            if (empty($products)) {
                break;
            }

            foreach ($products as $dropxlProduct) {
                $importResult = $this->importProduct($dropxlProduct);
                if ($importResult['success']) {
                    $imported++;
                } else {
                    $failed++;
                }
            }

            $hasMore = isset($result['data']['next_page_url']) && $result['data']['next_page_url'];
            $page++;
        } while ($hasMore);

        return ['success' => true, 'imported' => $imported, 'failed' => $failed];
    }

    /**
     * Resolve or create a local ProductCategory from a DropXL category name.
     */
    protected function resolveCategory(?string $categoryName): ?ProductCategory
    {
        if (! $categoryName) {
            return null;
        }

        return ProductCategory::firstOrCreate(
            ['name' => $categoryName],
            [
                'slug' => Str::slug($categoryName),
                'description' => 'Imported from DropXL',
            ]
        );
    }

    /**
     * Extract the products array from a DropXL API response payload.
     * Handles both paginated (Laravel-style) and plain array responses.
     */
    protected function extractProducts(mixed $responseData): array
    {
        if (isset($responseData['data']) && is_array($responseData['data'])) {
            return $responseData['data'];
        }

        if (isset($responseData['products']) && is_array($responseData['products'])) {
            return $responseData['products'];
        }

        if (is_array($responseData) && array_is_list($responseData)) {
            return $responseData;
        }

        return [];
    }
}
