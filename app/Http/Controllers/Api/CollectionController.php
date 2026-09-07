<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\OwnsTeamResources;
use App\Http\Controllers\Controller;
use App\Models\ProductCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CollectionController extends Controller
{
    use OwnsTeamResources;

    /**
     * Display a listing of collections.
     */
    public function index(Request $request): JsonResponse
    {
        $query = QueryBuilder::for(ProductCollection::class)
            ->allowedFilters(
                'name',
                AllowedFilter::exact('id'),
            )
            ->allowedSorts('name', 'created_at', 'updated_at')
            ->with('products');

        // Check if pagination is requested
        if ($request->has('per_page')) {
            $perPage = min((int) $request->input('per_page', 15), 100);
            $collections = $query->paginate($perPage);

            return response()->json([
                'data' => $collections->items(),
                'links' => [
                    'first' => $collections->url(1),
                    'last' => $collections->url($collections->lastPage()),
                    'prev' => $collections->previousPageUrl(),
                    'next' => $collections->nextPageUrl(),
                ],
                'meta' => [
                    'current_page' => $collections->currentPage(),
                    'from' => $collections->firstItem(),
                    'last_page' => $collections->lastPage(),
                    'per_page' => $collections->perPage(),
                    'to' => $collections->lastItem(),
                    'total' => $collections->total(),
                ],
            ]);
        } else {
            $collections = $query->get();
        }

        return response()->json($collections);
    }

    /**
     * Store a newly created collection.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasRole(['super_admin', 'admin']), 403);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:collections,slug',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Auto-generate slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        if ($teamId = $this->creationTeamId($request)) {
            $data['team_id'] = $teamId;
        }

        $collection = ProductCollection::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Collection created successfully',
            'data' => $collection,
        ], 201);
    }

    /**
     * Display the specified collection.
     */
    public function show(string $idOrSlug): JsonResponse
    {
        // Try to find by ID first, then by slug
        $collection = is_numeric($idOrSlug)
            ? ProductCollection::with('products')->find($idOrSlug)
            : ProductCollection::with('products')->where('slug', $idOrSlug)->first();

        if (! $collection) {
            return response()->json([
                'success' => false,
                'message' => 'Collection not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $collection,
        ]);
    }

    /**
     * Update the specified collection.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->hasRole(['super_admin', 'admin']), 403);

        $collection = ProductCollection::find($id);

        if (! $collection) {
            return response()->json([
                'success' => false,
                'message' => 'Collection not found',
            ], 404);
        }

        $this->assertActorOwns($request, $collection);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('collections', 'slug')->ignore($id)],
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Auto-generate slug if name is updated but slug is not provided
        if (isset($data['name']) && ! isset($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $collection->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Collection updated successfully',
            'data' => $collection->fresh(),
        ]);
    }

    /**
     * Add products to a collection.
     */
    public function addProducts(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->hasRole(['super_admin', 'admin']), 403);

        $collection = ProductCollection::find($id);

        if (! $collection) {
            return response()->json([
                'success' => false,
                'message' => 'Collection not found',
            ], 404);
        }

        $this->assertActorOwns($request, $collection);

        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array',
            'product_ids.*' => 'required|integer|exists:products,id',
            'quantities' => 'sometimes|array',
            'quantities.*' => 'integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $productIds = $request->input('product_ids');
        $quantities = $request->input('quantities', []);

        // Prepare sync data with quantities
        $syncData = [];
        foreach ($productIds as $index => $productId) {
            $syncData[$productId] = [
                'quantity' => $quantities[$index] ?? 1,
            ];
        }

        // Attach products without detaching existing ones
        $collection->products()->syncWithoutDetaching($syncData);

        return response()->json([
            'success' => true,
            'message' => 'Products added to collection successfully',
            'data' => $collection->load('products'),
        ]);
    }

    /**
     * Remove products from a collection.
     */
    public function removeProducts(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->hasRole(['super_admin', 'admin']), 403);

        $collection = ProductCollection::find($id);

        if (! $collection) {
            return response()->json([
                'success' => false,
                'message' => 'Collection not found',
            ], 404);
        }

        $this->assertActorOwns($request, $collection);

        $validator = Validator::make($request->all(), [
            'product_ids' => 'required|array',
            'product_ids.*' => 'required|integer|exists:products,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $productIds = $request->input('product_ids');

        // Detach specified products
        $collection->products()->detach($productIds);

        return response()->json([
            'success' => true,
            'message' => 'Products removed from collection successfully',
            'data' => $collection->load('products'),
        ]);
    }

    /**
     * Soft delete the specified collection.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        abort_unless($request->user()?->hasRole(['super_admin', 'admin']), 403);

        $collection = ProductCollection::find($id);

        if (! $collection) {
            return response()->json([
                'success' => false,
                'message' => 'Collection not found',
            ], 404);
        }

        $this->assertActorOwns($request, $collection);

        $collection->delete();

        return response()->json([
            'success' => true,
            'message' => 'Collection deleted successfully',
        ]);
    }
}
