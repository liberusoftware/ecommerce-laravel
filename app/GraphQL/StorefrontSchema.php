<?php

namespace App\GraphQL;

use App\Exceptions\CheckoutException;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductOptionChoice;
use App\Models\ProductOptionGroup;
use App\Models\ProductVariant;
use App\Services\CartService;
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

    private ?ObjectType $selectedOption = null;

    private ?ObjectType $variantValue = null;

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
                        ->with(['products' => fn ($q) => $q->listed()->orderBy('products.id')->limit(self::MAX_LIST)])
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
                        // Choice ids from Product.optionGroups. Never a price.
                        'options' => Type::listOf(Type::nonNull(Type::int())),
                        // Required when Product.hasVariants: an id from Product.variants.
                        'variantId' => Type::int(),
                    ],
                    'resolve' => fn ($root, array $args, array $context) => $this->addToCart($args, $context),
                ],
                'updateCartItem' => [
                    'type' => Type::nonNull($cartItem),
                    'args' => [
                        'id' => Type::nonNull(Type::int()),
                        'quantity' => Type::nonNull(Type::int()),
                    ],
                    'resolve' => fn ($root, array $args, array $context) => $this->updateCartItem($args, $context),
                ],
                'removeCartItem' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'args' => ['id' => Type::nonNull(Type::int())],
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
                'sku' => Type::string(),
                // With variants, the lowest active variant price; see priceMin/priceMax.
                'price' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn (Product $p) => $p->sellingPrice()],
                'priceMin' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn (Product $p) => $p->hasVariants() ? ($p->variantPriceRange()[0] ?? $p->sellingPrice()) : $p->sellingPrice()],
                'priceMax' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn (Product $p) => $p->hasVariants() ? ($p->variantPriceRange()[1] ?? $p->sellingPrice()) : $p->sellingPrice()],
                'regularPrice' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn (Product $p) => (float) $p->price],
                'onSale' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn (Product $p) => $p->isOnSale()],
                // Null when the product's own stock is not counted, which includes every product with variants.
                'inventoryCount' => ['type' => Type::int(), 'resolve' => fn (Product $p) => $p->tracksInventory() ? $p->inventory_count : null],
                'inStock' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn (Product $p) => $p->isInStock()],
                'isDownloadable' => ['type' => Type::boolean(), 'resolve' => fn (Product $p) => (bool) $p->is_downloadable],
                'isFeatured' => ['type' => Type::boolean(), 'resolve' => fn (Product $p) => (bool) $p->is_featured],
                'featuredImage' => ['type' => Type::string(), 'resolve' => fn (Product $p) => $p->featured_image],
                'optionGroups' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($this->optionGroupType()))),
                    'resolve' => fn (Product $p) => $p->isDonationBased() ? [] : $p->optionGroups()->with('choices')->get(),
                ],
                'hasVariants' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn (Product $p) => $p->hasVariants()],
                'variantAttributes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($this->variantAttributeType()))),
                    'resolve' => fn (Product $p) => $p->hasVariants() ? ($p->variant_attributes ?? []) : [],
                ],
                // Inactive variants are listed so a client can show them as unavailable.
                'variants' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($this->variantType()))),
                    'resolve' => fn (Product $p) => $p->variants,
                ],
            ],
        ]);
    }

    private function variantAttributeType(): ObjectType
    {
        return new ObjectType([
            'name' => 'VariantAttribute',
            'fields' => [
                'name' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn (array $a) => $a['name']],
                'values' => ['type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))), 'resolve' => fn (array $a) => $a['values']],
            ],
        ]);
    }

    private function variantValueType(): ObjectType
    {
        return $this->variantValue ??= new ObjectType([
            'name' => 'VariantValue',
            'fields' => [
                'name' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn (array $v) => $v['name']],
                'value' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn (array $v) => $v['value']],
            ],
        ]);
    }

    private function variantType(): ObjectType
    {
        return new ObjectType([
            'name' => 'ProductVariant',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'sku' => Type::string(),
                'label' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn (ProductVariant $v) => $v->label()],
                'price' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn (ProductVariant $v) => $v->sellingPrice()],
                'attributes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($this->variantValueType()))),
                    'resolve' => fn (ProductVariant $v) => $v->attribute_values ?? [],
                ],
                'isAvailable' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn (ProductVariant $v) => $v->is_active],
                'inStock' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn (ProductVariant $v) => $v->isPurchasable()],
                // Null when the variant's stock is not counted.
                'inventoryCount' => ['type' => Type::int(), 'resolve' => fn (ProductVariant $v) => $v->tracksInventory() ? $v->inventory_count : null],
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
                        : $c->products()->listed()->orderBy('products.id')->limit(self::MAX_LIST)->get(),
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
                'variantId' => ['type' => Type::int(), 'resolve' => fn (CartItem $i) => $i->product_variant_id],
                'variantAttributes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($this->variantValueType()))),
                    'resolve' => fn (CartItem $i) => $i->variant?->attribute_values ?? [],
                ],
                'sku' => ['type' => Type::string(), 'resolve' => fn (CartItem $i) => $i->product_variant_id !== null ? $i->variant?->sku : $i->product?->sku],
                'quantity' => Type::nonNull(Type::int()),
                'options' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($this->selectedOptionType()))),
                    'resolve' => fn (CartItem $i) => $i->options ?? [],
                ],
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
                'items' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($this->orderLineType()))),
                    'resolve' => fn (Order $o) => $o->items,
                ],
            ],
        ]);
    }

    /** An order line as it was sold: a snapshot, never the live catalogue. */
    private function orderLineType(): ObjectType
    {
        return new ObjectType([
            'name' => 'OrderLine',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'productId' => ['type' => Type::int(), 'resolve' => fn (OrderItem $i) => $i->product_id],
                'variantId' => ['type' => Type::int(), 'resolve' => fn (OrderItem $i) => $i->product_variant_id],
                'productName' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn (OrderItem $i) => $i->displayName()],
                'sku' => Type::string(),
                'variantAttributes' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($this->variantValueType()))),
                    'resolve' => fn (OrderItem $i) => $i->variant_attributes ?? [],
                ],
                'options' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull($this->selectedOptionType()))),
                    'resolve' => fn (OrderItem $i) => $i->options ?? [],
                ],
                'quantity' => Type::nonNull(Type::int()),
                'price' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn (OrderItem $i) => (float) $i->price],
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
            ->listed()
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
            return Product::active()->find($args['id']);
        }
        if (! empty($args['slug'])) {
            return Product::active()->where('slug', $args['slug'])->first();
        }

        throw new Error('Provide either id or slug.');
    }

    private function addToCart(array $args, array $context): CartItem
    {
        $user = $this->requireUser($context);
        $this->assertPositive($args['quantity']);

        $product = $this->findProduct($args['productId']);

        try {
            return app(CartService::class)->for($user)->add($product, $args['quantity'], $args['options'] ?? [], null, $args['variantId'] ?? null);
        } catch (CheckoutException $e) {
            throw new Error($e->getMessage());
        }
    }

    private function updateCartItem(array $args, array $context): CartItem
    {
        $user = $this->requireUser($context);
        $this->assertPositive($args['quantity']);

        $cart = app(CartService::class)->for($user);
        $line = $cart->line($args['id']) ?? throw new Error('That item is not in your cart.');

        try {
            return $cart->setQuantity($line, $args['quantity']);
        } catch (CheckoutException $e) {
            throw new Error($e->getMessage());
        }
    }

    private function removeCartItem(array $args, array $context): bool
    {
        return app(CartService::class)->for($this->requireUser($context))->remove($args['id']);
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
                'shippingAddress' => Type::string(),
                'shippingName' => Type::string(),
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

        // Weight is per product, and one product may be several lines.
        $cartByProduct = [];
        foreach ($cart as $item) {
            $cartByProduct[$item->product_id]['quantity'] = ($cartByProduct[$item->product_id]['quantity'] ?? 0) + $item->quantity;
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
        return Product::active()->find($productId) ?? throw new Error('Product not found.');
    }

    private function assertPositive(int $quantity): void
    {
        if ($quantity < 1) {
            throw new Error('Quantity must be at least 1.');
        }
    }

    private function selectedOptionType(): ObjectType
    {
        return $this->selectedOption ??= new ObjectType([
            'name' => 'SelectedOption',
            'fields' => [
                'choiceId' => ['type' => Type::nonNull(Type::int()), 'resolve' => fn (array $o) => $o['choice_id']],
                'group' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn (array $o) => $o['group']],
                'name' => ['type' => Type::nonNull(Type::string()), 'resolve' => fn (array $o) => $o['name']],
                'priceAdjustment' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn (array $o) => (float) $o['price_adjustment']],
            ],
        ]);
    }

    private function optionGroupType(): ObjectType
    {
        $choice = new ObjectType([
            'name' => 'OptionChoice',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'name' => Type::nonNull(Type::string()),
                'priceAdjustment' => ['type' => Type::nonNull(Type::float()), 'resolve' => fn (ProductOptionChoice $c) => (float) $c->price_adjustment],
                'isDefault' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn (ProductOptionChoice $c) => $c->is_default],
                'isAvailable' => ['type' => Type::nonNull(Type::boolean()), 'resolve' => fn (ProductOptionChoice $c) => $c->is_available],
            ],
        ]);

        return new ObjectType([
            'name' => 'OptionGroup',
            'fields' => [
                'id' => Type::nonNull(Type::int()),
                'name' => Type::nonNull(Type::string()),
                'minSelect' => ['type' => Type::nonNull(Type::int()), 'resolve' => fn (ProductOptionGroup $g) => $g->min_select],
                'maxSelect' => ['type' => Type::int(), 'resolve' => fn (ProductOptionGroup $g) => $g->max_select],
                'choices' => ['type' => Type::nonNull(Type::listOf(Type::nonNull($choice))), 'resolve' => fn (ProductOptionGroup $g) => $g->choices],
            ],
        ]);
    }
}
