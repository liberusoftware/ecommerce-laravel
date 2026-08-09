<?php

namespace App\GraphQL;

use App\Exceptions\CheckoutException;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Services\HeadlessCheckoutService;
use App\Services\ShippingService;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * Code-first storefront GraphQL schema (webonyx). Read queries are public for the
 * catalog and user-scoped for me/orders; cart mutations require an authenticated user
 * and mirror the REST cart controller (same stock guard, same user_id scoping = IDOR
 * guard). The authenticated user (or null) is passed in as the resolver $context.
 */
class StorefrontSchema
{
    /**
     * The ceiling on any list field that takes no pagination arguments.
     *
     * Depth and complexity limits bound the *shape* of a query, not the number
     * of rows a field returns: `collections { products { id } }` is depth 3 and
     * complexity well under the limit, and used to return the whole catalogue
     * once per collection. `throttle:api` caps request count, not per-request
     * cost, so nothing else bounded these.
     */
    private const MAX_LIST = 100;

    public function build(): Schema
    {
        $product = $this->productType();
        $collection = $this->collectionType($product);
        $cartItem = $this->cartItemType($product);
        $order = $this->orderType();
        $user = $this->userType();
        $productPage = $this->productPageType($product);

        $query = new ObjectType([
            'name' => 'Query',
            'fields' => [
                'products' => [
                    'type' => Type::nonNull($productPage),
                    'args' => [
                        'search' => Type::string(),
                        'page' => Type::int(),
                        'perPage' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args) => $this->resolveProducts($args),
                ],
                'product' => [
                    'type' => $product,
                    'args' => ['id' => Type::int(), 'slug' => Type::string()],
                    'resolve' => fn ($root, array $args) => $this->resolveProduct($args),
                ],
                'collections' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($collection))),
                    // Products are eager-loaded rather than resolved per collection:
                    // `Collection.products` was one query per collection.
                    'resolve' => fn () => ProductCollection::query()
                        ->with(['products' => fn ($q) => $q->orderBy('products.id')->limit(self::MAX_LIST)])
                        ->orderBy('name')
                        ->limit(self::MAX_LIST)
                        ->get(),
                ],
                'me' => [
                    'type' => $user,
                    'resolve' => fn ($root, array $args, array $context) => $context['user'] ?? null,
                ],
                'orders' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($order))),
                    'resolve' => fn ($root, array $args, array $context) => ($u = $context['user'] ?? null)
                        ? Order::where('user_id', $u->id)->latest('id')->limit(self::MAX_LIST)->get()
                        : [],
                ],
            ],
        ]);

        $mutation = new ObjectType([
            'name' => 'Mutation',
            'fields' => [
                'addToCart' => [
                    'type' => Type::nonNull($cartItem),
                    'args' => [
                        'productId' => Type::nonNull(Type::int()),
                        'quantity' => Type::nonNull(Type::int()),
                    ],
                    'resolve' => fn ($root, array $args, array $context) => $this->addToCart($args, $context),
                ],
                'updateCartItem' => [
                    'type' => Type::nonNull($cartItem),
                    'args' => [
                        'productId' => Type::nonNull(Type::int()),
                        'quantity' => Type::nonNull(Type::int()),
                    ],
                    'resolve' => fn ($root, array $args, array $context) => $this->updateCartItem($args, $context),
                ],
                'removeCartItem' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'args' => ['productId' => Type::nonNull(Type::int())],
                    'resolve' => fn ($root, array $args, array $context) => $this->removeCartItem($args, $context),
                ],
                'checkout' => [
                    'type' => Type::nonNull($order),
                    'args' => ['input' => Type::nonNull($this->checkoutInputType())],
                    'resolve' => fn ($root, array $args, array $context) => $this->checkout($args['input'], $context),
                ],
                // A mutation (not a query): it persists a ShippingQuote per rate so the
                // returned ids can be passed to checkout, where the STORED amount is billed.
                'shippingRates' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($this->shippingRateType()))),
                    'args' => ['input' => Type::nonNull($this->shippingRatesInputType())],
                    'resolve' => fn ($root, array $args, array $context) => $this->shippingRates($args['input'], $context),
                ],
            ],
        ]);

        return new Schema(['query' => $query, 'mutation' => $mutation]);
    }

    private function productType(): ObjectType
    {
        return new ObjectType([
            'name' => 'Product',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'name' => Type::nonNull(Type::string()),
                'slug' => Type::string(),
                'description' => Type::string(),
                'shortDescription' => ['type' => Type::string(), 'resolve' => fn (Product $p) => $p->short_description],
                'price' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn (Product $p) => (float) $p->price],
                'inventoryCount' => ['type' => Type::int(), 'resolve' => fn (Product $p) => $p->inventory_count],
                'isDownloadable' => ['type' => Type::boolean(), 'resolve' => fn (Product $p) => (bool) $p->is_downloadable],
                'isFeatured' => ['type' => Type::boolean(), 'resolve' => fn (Product $p) => (bool) $p->is_featured],
                'featuredImage' => ['type' => Type::string(), 'resolve' => fn (Product $p) => $p->featured_image],
            ],
        ]);
    }

    private function productPageType(ObjectType $product): ObjectType
    {
        return new ObjectType([
            'name' => 'ProductPage',
            'fields' => [
                'data' => Type::nonNull(Type::listOf(Type::nonNull($product))),
                'total' => Type::nonNull(Type::int()),
                'currentPage' => Type::nonNull(Type::int()),
                'perPage' => Type::nonNull(Type::int()),
                'lastPage' => Type::nonNull(Type::int()),
            ],
        ]);
    }

    private function collectionType(ObjectType $product): ObjectType
    {
        return new ObjectType([
            'name' => 'Collection',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'name' => Type::nonNull(Type::string()),
                'slug' => Type::string(),
                'products' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($product))),
                    // Eager-loaded and already bounded by the `collections` query.
                    // Loading here would be the N+1 back again.
                    'resolve' => fn (ProductCollection $c) => $c->relationLoaded('products')
                        ? $c->products
                        : $c->products()->orderBy('products.id')->limit(self::MAX_LIST)->get(),
                ],
            ],
        ]);
    }

    private function cartItemType(ObjectType $product): ObjectType
    {
        return new ObjectType([
            'name' => 'CartItem',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'productId' => ['type' => Type::nonNull(Type::int()), 'resolve' => fn (CartItem $i) => $i->product_id],
                'quantity' => Type::nonNull(Type::int()),
                'price' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn (CartItem $i) => (float) $i->price],
                'lineTotal' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn (CartItem $i) => round((float) $i->price * $i->quantity, 2)],
                'product' => ['type' => $product, 'resolve' => fn (CartItem $i) => $i->product],
            ],
        ]);
    }

    private function orderType(): ObjectType
    {
        return new ObjectType([
            'name' => 'Order',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'status' => Type::nonNull(Type::string()),
                'totalAmount' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn (Order $o) => (float) $o->total_amount],
                'taxAmount' => ['type' => Type::float(), 'resolve' => fn (Order $o) => (float) $o->tax_amount],
                'shippingCost' => ['type' => Type::float(), 'resolve' => fn (Order $o) => (float) $o->shipping_cost],
                'shippingCarrier' => ['type' => Type::string(), 'resolve' => fn (Order $o) => $o->shipping_carrier],
                'shippingService' => ['type' => Type::string(), 'resolve' => fn (Order $o) => $o->shipping_service],
                'billingCountry' => ['type' => Type::string(), 'resolve' => fn (Order $o) => $o->billing_country],
                'createdAt' => ['type' => Type::string(), 'resolve' => fn (Order $o) => $o->created_at?->toIso8601String()],
            ],
        ]);
    }

    private function userType(): ObjectType
    {
        return new ObjectType([
            'name' => 'User',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'name' => Type::nonNull(Type::string()),
                'email' => Type::nonNull(Type::string()),
            ],
        ]);
    }

    private function resolveProducts(array $args): array
    {
        $perPage = min(max((int) ($args['perPage'] ?? 15), 1), 100);
        $page = max((int) ($args['page'] ?? 1), 1);
        $search = $args['search'] ?? null;

        $paginator = Product::query()
            ->when($search, fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'lastPage' => $paginator->lastPage(),
        ];
    }

    private function resolveProduct(array $args): ?Product
    {
        if (! empty($args['id'])) {
            return Product::find($args['id']);
        }
        if (! empty($args['slug'])) {
            return Product::where('slug', $args['slug'])->first();
        }

        throw new Error('Provide either id or slug.');
    }

    private function addToCart(array $args, array $context): CartItem
    {
        $user = $this->requireUser($context);
        $this->assertPositive($args['quantity']);

        $product = $this->findProduct($args['productId']);
        $item = CartItem::firstOrNew(['user_id' => $user->id, 'product_id' => $product->id]);
        $quantity = ($item->quantity ?? 0) + $args['quantity'];

        $this->assertStock($product, $quantity);

        $item->fill([
            'quantity' => $quantity,
            'price' => $product->price,
        ])->save();

        return $item;
    }

    private function updateCartItem(array $args, array $context): CartItem
    {
        $user = $this->requireUser($context);
        $this->assertPositive($args['quantity']);

        $product = $this->findProduct($args['productId']);
        $this->assertStock($product, $args['quantity']);

        $item = CartItem::where('user_id', $user->id)->where('product_id', $product->id)->first();
        if ($item === null) {
            throw new Error('That product is not in your cart.');
        }

        $item->update(['quantity' => $args['quantity']]);

        return $item;
    }

    private function removeCartItem(array $args, array $context): bool
    {
        $user = $this->requireUser($context);
        CartItem::where('user_id', $user->id)->where('product_id', $args['productId'])->delete();

        return true;
    }

    private function checkoutInputType(): InputObjectType
    {
        return new InputObjectType([
            'name' => 'CheckoutInput',
            'fields' => [
                'country' => Type::nonNull(Type::string()),
                'paymentMethod' => Type::string(),   // defaults to 'stripe'
                'stripeToken' => Type::string(),
                'shippingQuoteId' => Type::int(),     // a persisted ShippingQuote; its stored amount is billed
                'state' => Type::string(),
                'city' => Type::string(),
                'postalCode' => Type::string(),
                'couponCode' => Type::string(),       // re-validated against the live subtotal
                'vatNumber' => Type::string(),        // EU VAT number → VIES-checked reverse charge
                'dropship' => Type::boolean(),
                'supplierId' => Type::string(),
                'recipientName' => Type::string(),
                'recipientEmail' => Type::string(),
                'giftMessage' => Type::string(),
            ],
        ]);
    }

    private function checkout(array $input, array $context): Order
    {
        $user = $this->requireUser($context);

        try {
            return app(HeadlessCheckoutService::class)->place($user, $input);
        } catch (CheckoutException $e) {
            // Client-safe failures (empty cart, out of stock, bad quote, declined) are
            // surfaced; anything else bubbles and webonyx masks it as an internal error.
            throw new Error($e->getMessage());
        }
    }

    private function shippingRateType(): ObjectType
    {
        return new ObjectType([
            'name' => 'ShippingRate',
            'fields' => [
                'id' => Type::nonNull(Type::int()),   // the persisted quote id — pass to checkout
                'carrier' => Type::nonNull(Type::string()),
                'service' => Type::nonNull(Type::string()),
                'amount' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn ($q) => (float) $q->amount],
                'currency' => Type::nonNull(Type::string()),
                'deliveryDays' => ['type' => Type::int(), 'resolve' => fn ($q) => $q->delivery_days],
            ],
        ]);
    }

    private function shippingRatesInputType(): InputObjectType
    {
        return new InputObjectType([
            'name' => 'ShippingRatesInput',
            'fields' => [
                'country' => Type::nonNull(Type::string()),
                'state' => Type::string(),
                'city' => Type::string(),
                'postalCode' => Type::string(),
            ],
        ]);
    }

    private function shippingRates(array $input, array $context): array
    {
        $user = $this->requireUser($context);

        $cart = CartItem::where('user_id', $user->id)->get();
        if ($cart->isEmpty()) {
            return [];
        }

        $cartByProduct = [];
        foreach ($cart as $item) {
            $cartByProduct[$item->product_id] = ['quantity' => $item->quantity];
        }

        $to = [
            'country' => $input['country'],
            'state' => $input['state'] ?? null,
            'city' => $input['city'] ?? null,
            'zip' => $input['postalCode'] ?? null,
        ];

        // Persist quotes scoped to this user (headless clients have no session), so the
        // returned ids resolve in checkout by user_id.
        return app(ShippingService::class)
            ->quoteLiveRates($cartByProduct, $to, 'api', $user->id)
            ->all();
    }

    private function requireUser(array $context)
    {
        return $context['user'] ?? throw new Error('Unauthenticated.');
    }

    private function findProduct(int $productId): Product
    {
        return Product::find($productId) ?? throw new Error('Product not found.');
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity < 1) {
            throw new Error('Quantity must be at least 1.');
        }
    }

    private function assertStock(Product $product, int $quantity): void
    {
        if ($product->inventory_count < $quantity) {
            throw new Error('Requested quantity exceeds available stock.');
        }
    }
}
