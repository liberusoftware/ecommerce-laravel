<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\OwnsTeamResources;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Support\StoreAccess;
use App\Support\TeamPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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

        $products = $query->with(['category', 'images'])->paginate($perPage);

        return response()->json($products);
    }

    /**
     * Display the specified product by ID or slug.
     */
    public function show(Request $request, string $identifier): JsonResponse
    {
        // Try to find by ID if identifier is numeric, otherwise by slug
        $product = is_numeric($identifier)
            ? Product::with(['category', 'images', 'tags', 'review', 'rating'])
                ->unless($this->isStaff($request), fn ($q) => $q->active())
                ->find($identifier)
            : Product::with(['category', 'images', 'tags', 'review', 'rating'])
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
            'data' => $product,
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
            'price' => 'required|numeric|min:0',
            'category_id' => ['required', $this->categoryInThisStore()],
            'featured_image' => 'nullable|string',
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // store_id and team_id come from the storefront the request arrived on.
        $product = Product::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => $product->load(['category', 'images']),
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $product->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => $product->load(['category', 'images']),
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

    private function isStaff(Request $request): bool
    {
        return StoreAccess::allows($request->user(), TeamPermission::CATALOGUE_VIEW, null, 'view_any_product');
    }

    private function categoryInThisStore(): Exists
    {
        return Rule::exists('product_categories', 'id')->where('store_id', Store::current()?->getKey());
    }
}
