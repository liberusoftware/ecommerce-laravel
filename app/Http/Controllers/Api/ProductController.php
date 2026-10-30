<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\OwnsTeamResources;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Services\ProductVariantService;
use App\Support\StoreAccess;
use App\Support\TeamPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    use OwnsTeamResources;

    /**
     * Display a paginated listing of products.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        $perPage = min($perPage, 100); // Max 100 items per page

        // Staff see the whole catalogue; everyone else what the storefront lists.
        $query = Product::query()->unless($this->isStaff($request), fn ($q) => $q->listed());

        // Apply search filter if provided
        if ($request->filled('search')) {
            // The value is a bound parameter (injection-safe already); escape only
            // the LIKE wildcards so a literal % / _ can't match everything.
            $search = addcslashes(trim($request->input('search')), '%_\\');

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('short_description', 'like', '%'.$search.'%');
            });
        }

        // Apply category filter if provided
        if ($request->has('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        // Apply price range filters
        if ($request->has('price_min')) {
            $query->where('price', '>=', $request->input('price_min'));
        }

        if ($request->has('price_max')) {
            $query->where('price', '<=', $request->input('price_max'));
        }

        // Apply sorting — whitelist the column AND clamp the direction (orderBy()
        // throws on anything but asc/desc, which 500'd the endpoint).
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowedSortFields = ['name', 'price', 'created_at', 'updated_at'];
        if (in_array($sortBy, $allowedSortFields, true)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        $products = $query->with(['category', 'images', 'variants'])->paginate($perPage);
        $products->getCollection()->each->append(['has_variants', 'price_range']);

        return response()->json($products);
    }

    /**
     * Display the specified product by ID or slug.
     */
    public function show(Request $request, string $identifier): JsonResponse
    {
        // Try to find by ID if identifier is numeric, otherwise by slug
        $product = is_numeric($identifier)
            ? Product::with(['category', 'images', 'tags', 'review', 'rating', 'optionGroups.choices', 'variants'])
                ->unless($this->isStaff($request), fn ($q) => $q->active())
                ->find($identifier)
            : Product::with(['category', 'images', 'tags', 'review', 'rating', 'optionGroups.choices', 'variants'])
                ->unless($this->isStaff($request), fn ($q) => $q->active())
                ->where('slug', $identifier)
                ->first();

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $product->append(['has_variants', 'price_range']),
        ]);
    }

    /**
     * Store a newly created product.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::CATALOGUE_MANAGE, null, 'update_product'), 403);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'short_description' => 'required|string',
            'price' => 'required_without:variants|numeric|min:0',
            'category_id' => ['required', $this->categoryInThisStore()],
            'featured_image' => 'nullable|string',
            'track_inventory' => 'nullable|boolean',
            'inventory_count' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'is_downloadable' => 'nullable|boolean',
            'downloadable_file' => 'nullable|string',
            'download_limit' => 'nullable|integer|min:1',
            'expiration_time' => 'nullable|integer|min:1',
            'pricing_type' => 'nullable|string|in:fixed,free,donation',
            'suggested_price' => 'nullable|numeric|min:0',
            'minimum_price' => 'nullable|numeric|min:0',
            ...self::VARIANT_RULES,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            // store_id and team_id come from the storefront the request arrived on.
            $product = DB::transaction(function () use ($request, $data) {
                $product = Product::create(collect($data)->except(['variant_attributes', 'variants'])->all());
                $this->syncVariants($request, $product);

                return $product;
            });
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => $product->load(['category', 'images', 'variants'])->append(['has_variants', 'price_range']),
        ], 201);
    }

    /**
     * Update the specified product.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::CATALOGUE_MANAGE, null, 'update_product'), 403);

        $product = Product::find($id);

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        $this->assertActorOwns($request, $product);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'short_description' => 'sometimes|required|string',
            'price' => 'sometimes|required|numeric|min:0',
            'category_id' => ['sometimes', 'required', $this->categoryInThisStore()],
            'featured_image' => 'nullable|string',
            'track_inventory' => 'nullable|boolean',
            'inventory_count' => 'nullable|integer|min:0',
            'low_stock_threshold' => 'nullable|integer|min:0',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string',
            'is_downloadable' => 'nullable|boolean',
            'downloadable_file' => 'nullable|string',
            'download_limit' => 'nullable|integer|min:1',
            'expiration_time' => 'nullable|integer|min:1',
            'pricing_type' => 'nullable|string|in:fixed,free,donation',
            'suggested_price' => 'nullable|numeric|min:0',
            'minimum_price' => 'nullable|numeric|min:0',
            ...self::VARIANT_RULES,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = collect($validator->validated())->except(['variant_attributes', 'variants']);
        // Price and stock belong to the variants while there are any.
        if ($product->hasVariants() && ! $request->has('variants')) {
            $data = $data->except(['price', 'sale_price', 'inventory_count', 'track_inventory']);
        }

        try {
            DB::transaction(function () use ($request, $product, $data) {
                $product->update($data->all());
                $this->syncVariants($request, $product);
            });
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'errors' => $e->errors()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $product->load(['category', 'images', 'variants'])->append(['has_variants', 'price_range']),
        ]);
    }

    /**
     * Soft delete the specified product.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless(StoreAccess::allows($request->user(), TeamPermission::CATALOGUE_MANAGE, null, 'update_product'), 403);

        $product = Product::find($id);

        if (! $product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found',
            ], 404);
        }

        $this->assertActorOwns($request, $product);

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully',
        ]);
    }

    /**
     * `variants` replaces the whole set: a variant left out is removed, and one
     * sent without an id is matched to an existing one by its combination.
     * `inventory_count` is read only for a new variant; later changes go
     * through POST /inventory/adjust with a `variant_id`. An empty list with
     * no attributes removes every variant.
     */
    private const VARIANT_RULES = [
        'variant_attributes' => 'nullable|array',
        'variant_attributes.*.name' => 'required|string|max:50',
        'variant_attributes.*.values' => 'required|array|min:1',
        'variant_attributes.*.values.*' => 'string|max:100',
        'variants' => 'nullable|array',
        'variants.*.id' => 'nullable|integer',
        'variants.*.attribute_values' => 'required|array',
        'variants.*.attribute_values.*.name' => 'required|string',
        'variants.*.attribute_values.*.value' => 'required|string',
        'variants.*.sku' => 'nullable|string|max:100',
        'variants.*.price' => 'required|numeric|min:0',
        'variants.*.track_inventory' => 'nullable|boolean',
        'variants.*.inventory_count' => 'nullable|integer|min:0',
        'variants.*.is_active' => 'nullable|boolean',
    ];

    private function syncVariants(Request $request, Product $product): void
    {
        if (! $request->has('variants') && ! $request->has('variant_attributes')) {
            return;
        }

        app(ProductVariantService::class)->sync(
            $product,
            $request->input('variant_attributes', $product->variant_attributes),
            $request->input('variants', []),
        );
    }

    private function isStaff(Request $request): bool
    {
        return StoreAccess::allows($request->user(), TeamPermission::CATALOGUE_VIEW, null, 'view_any_product');
    }

    private function categoryInThisStore(): Exists
    {
        return Rule::exists('product_categories', 'id')->where('store_id', Store::current()?->getKey());
    }
}
