<?php

namespace App\Services;

use App\Exceptions\CheckoutException;
use App\Exceptions\InvalidProductConfigurationException;
use App\Factories\PaymentGatewayFactory;
use App\Jobs\DispatchDropshippingOrder;
use App\Models\CartItem;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Notifications\SupplierFailureNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * The shared money mechanics behind both checkout entry points — the web checkout
 * (session cart) and the headless GraphQL checkout (CartItem cart). Keeping stock
 * reservation, release and payment capture in one place stops the two paths from
 * drifting on the parts that are dangerous to get wrong.
 *
 * Each caller keeps its own orchestration (cart source, tax/coupon/shipping, error
 * presentation) and calls these primitives; they do not encode any web- or
 * GraphQL-specific behaviour.
 *
 * @phpstan-type LineItem array{product_id: int, quantity: int, price: float|string, product_variant_id?: ?int, product_name?: ?string, sku?: ?string, variant_attributes?: ?array, options?: ?array}
 */
class CheckoutService
{
    public function __construct(private CouponService $couponService, private ProductOptionResolver $resolver, private Inventory $inventory) {}

    /**
     * The order lines for a cart, as both checkout paths write them: the name,
     * SKU, variant and choices are frozen here, so the order never reads the
     * catalogue again.
     *
     * @param  iterable<CartItem>  $items
     * @return list<LineItem>
     */
    public function orderLines(iterable $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = [
                'product_id' => (int) $item->product_id,
                'product_variant_id' => $item->product_variant_id,
                'product_name' => $item->product?->name,
                'sku' => $item->product_variant_id !== null ? $item->variant?->sku : $item->product?->sku,
                'variant_attributes' => $item->variant?->attribute_values,
                'options' => $item->options ?: null,
                'quantity' => (int) $item->quantity,
                'price' => $item->price,
            ];
        }

        return $lines;
    }

    /**
     * Every line must still be an active product: a product taken down after it
     * was added to a cart is no longer for sale.
     *
     * @param  iterable<int>  $productIds
     */
    public function assertPurchasable(iterable $productIds): void
    {
        $productIds = collect($productIds)->unique()->values();

        if (Product::active()->whereKey($productIds)->count() !== $productIds->count()) {
            throw new CheckoutException('Some items in your cart are no longer available.');
        }
    }

    /**
     * Every line's stock — its variant's, or the product's — must cover what
     * the cart holds of it across all lines. Whether a variant is still offered
     * at all is assertCartPricesCurrent()'s question, which says so plainly.
     *
     * @param  iterable<CartItem>  $items
     */
    public function assertInStock(iterable $items): void
    {
        $held = [];
        foreach ($items as $item) {
            $key = $item->product_variant_id !== null ? 'v'.$item->product_variant_id : 'p'.$item->product_id;
            $held[$key] ??= ['holder' => $this->inventory->holderFor((int) $item->product_id, $item->product_variant_id), 'quantity' => 0];
            $held[$key]['quantity'] += (int) $item->quantity;
        }

        foreach ($held as ['holder' => $holder, 'quantity' => $quantity]) {
            if ($holder instanceof ProductVariant && ($holder->trashed() || ! $holder->is_active)) {
                continue;
            }

            if ($holder === null || ! $holder->canSupply($quantity)) {
                throw new CheckoutException('Some items in your cart are no longer available in the requested quantity.');
            }
        }
    }

    /**
     * Brings every line to the catalogue's current price and choices. If any
     * price moved, the cart is left corrected and checkout refuses, so the
     * shopper sees the new total before anything is charged. A line whose
     * choices are no longer valid is refused outright: there is no price to
     * correct it to.
     *
     * @param  iterable<CartItem>  $items
     */
    public function assertCartPricesCurrent(iterable $items): void
    {
        $changed = false;
        $invalid = [];
        $withdrawn = [];

        foreach ($items as $item) {
            if ($item->product === null) {
                continue;
            }

            if ($item->product->isDonationBased()) {
                $current = max((float) $item->price, (float) $item->product->minimum_price);
                $options = $item->options;
            } else {
                try {
                    $configuration = $this->resolver->resolve($item->product, $item->choiceIds(), $item->product_variant_id);
                } catch (InvalidProductConfigurationException) {
                    if ($item->product_variant_id !== null || $item->product->hasVariants()) {
                        $withdrawn[] = $item->product->name;
                    } else {
                        $invalid[] = $item->product->name;
                    }

                    continue;
                }
                $current = $configuration->unitPrice;
                $options = $configuration->options ?: null;
            }

            $moved = abs($current - (float) $item->price) >= 0.005;
            if ($moved || $options != $item->options) {
                $item->update(['price' => $current, 'options' => $options]);
            }
            $changed = $changed || $moved;
        }

        if ($withdrawn !== []) {
            throw new InvalidProductConfigurationException('The version of '.implode(', ', array_unique($withdrawn)).' in your cart, or an option chosen for it, is no longer available. Remove the item and add it again.');
        }

        if ($invalid !== []) {
            throw new InvalidProductConfigurationException('Some options chosen for '.implode(', ', array_unique($invalid)).' are no longer available. Remove the item and add it again.');
        }

        if ($changed) {
            throw new CheckoutException('Prices in your cart have changed. Please review your cart and check out again.');
        }
    }

    /** The store must accept the gateway the shopper chose. */
    public function assertGatewayAccepted(string $gateway): void
    {
        $accepted = Store::current()?->paymentGateways() ?? array_keys(PaymentGatewayFactory::GATEWAYS);

        if (! in_array($gateway, $accepted, true)) {
            throw new CheckoutException('That payment method is not available for this store.');
        }
    }

    /**
     * Re-validate a coupon against the LIVE subtotal and return the discount to apply.
     * Never trust a client- or session-cached figure: recomputing here also drops a
     * coupon that has since expired, hit its usage limit, or fallen below min spend.
     *
     * @return array{valid: bool, discount: float, code: ?string}
     */
    public function resolveCouponDiscount(?string $code, float $subtotal): array
    {
        if (empty($code)) {
            return ['valid' => false, 'discount' => 0.0, 'code' => null];
        }

        $result = $this->couponService->validateAndApplyCoupon($code, $subtotal);

        return $result['valid']
            ? ['valid' => true, 'discount' => (float) $result['discount'], 'code' => $code]
            : ['valid' => false, 'discount' => 0.0, 'code' => null];
    }

    /**
     * Re-check a coupon's usage limit under a row lock, inside the order transaction.
     *
     * Coupon usage is derived from the order count, so resolveCouponDiscount validating
     * it up front leaves a TOCTOU window: two simultaneous checkouts of a max_uses=1
     * coupon can both pass before either order commits. Locking the coupon row here
     * serialises those checkouts — the first to commit consumes the use, and the next,
     * counting orders under the lock, is rejected. (Row locks are a MySQL feature; the
     * re-check logic still runs on sqlite so the guard is testable.)
     *
     * @throws CheckoutException when the coupon has since become unusable
     */
    public function assertCouponAvailable(?string $code): void
    {
        if (empty($code)) {
            return;
        }

        $coupon = Coupon::where('code', $code)->lockForUpdate()->first();

        if ($coupon !== null && ! $coupon->isValid()) {
            throw new CheckoutException('This coupon is no longer available.');
        }
    }

    /**
     * Issue a download token + 30-day expiry for each downloadable line on a paid
     * order. Reads the order's own items (not a cart), so it works for any checkout.
     */
    public function grantDownloads(Order $order): void
    {
        foreach ($order->items as $item) {
            $product = Product::find($item->product_id);
            if ($product && $product->is_downloadable) {
                $item->update([
                    'download_link' => Str::random(64),   // a token, not a full URL
                    'download_expires_at' => now()->addDays(30),
                    'download_count' => 0,
                ]);
            }
        }
    }

    /**
     * The one supplier that fulfils the cart's physical lines, from the products themselves.
     * Null when the merchant ships everything, or when lines would go to more than one supplier.
     *
     * @param  iterable<object{product: ?Product}>  $items
     */
    public function supplierFor(iterable $items): ?string
    {
        $suppliers = collect($items)->map(fn ($item) => $item->product)->filter()
            ->reject(fn (Product $product) => $product->is_downloadable)
            ->pluck('supplier_id')->filter()->unique();

        if ($suppliers->count() > 1) {
            Log::warning('Cart spans suppliers '.$suppliers->implode(', ').'; not drop-shipped automatically.');
        }

        return $suppliers->count() === 1 ? $suppliers->first() : null;
    }

    /**
     * Queue supplier placement for a drop-shipped order. Returns true when queued;
     * on failure it records SUPPLIER_FAILED + notifies staff and returns false (never
     * throws — the order is already paid). Callers decide how to surface the outcome.
     */
    public function queueDropship(Order $order, string $supplierId): bool
    {
        try {
            $order->update(['supplier_id' => $supplierId, 'is_dropshipped' => true]);
            // Queued first: the job places only queued orders, and a sync queue runs it right here.
            $order->transitionTo(Order::STATUS_SUPPLIER_QUEUED, notes: "Supplier order queued ({$supplierId})");
            DispatchDropshippingOrder::dispatch($order->id, $supplierId);

            return true;
        } catch (Throwable $e) {
            Log::error('Dropshipping dispatch error: '.$e->getMessage());
            $order->transitionTo(Order::STATUS_SUPPLIER_FAILED, notes: 'Dropshipping dispatch error: '.$e->getMessage());
            Notification::route('mail', config('mail.from.address'))
                ->notify(new SupplierFailureNotification("Error queuing dropshipping order for order {$order->id}: ".$e->getMessage()));

            return false;
        }
    }

    /**
     * Create the order's line items and RESERVE their stock with a guarded atomic
     * decrement — the variant's for a variant line, the product's otherwise. Call
     * inside a transaction: on the first shortfall this throws, the transaction
     * rolls back, and no payment is taken. Reserving before charging is what
     * prevents charging a customer for stock we can't fulfil.
     *
     * @param  array<int, LineItem>  $lineItems
     *
     * @throws CheckoutException when any line can't be fully reserved
     */
    public function reserveStock(Order $order, array $lineItems): void
    {
        foreach ($lineItems as $line) {
            $variantId = $line['product_variant_id'] ?? null;

            $order->items()->create([
                'product_id' => $line['product_id'],
                'product_variant_id' => $variantId,
                'product_name' => $line['product_name'] ?? Product::withTrashed()->whereKey($line['product_id'])->value('name'),
                'sku' => $line['sku'] ?? null,
                'variant_attributes' => $line['variant_attributes'] ?? null,
                'options' => $line['options'] ?? null,
                'quantity' => $line['quantity'],
                'price' => $line['price'],
            ]);

            $holder = $this->inventory->holderFor((int) $line['product_id'], $variantId);

            // A product with variants holds no stock of its own: a line must name one.
            if ($holder === null || ($holder instanceof Product && $holder->hasVariants())) {
                throw new CheckoutException('Some items in your cart are no longer available in the requested quantity.');
            }

            // An untracked product or variant has nothing to reserve.
            if (! $holder->tracksInventory()) {
                continue;
            }

            if (! $this->inventory->adjust($holder, -$line['quantity'], 'order', $order)) {
                throw new CheckoutException('Some items in your cart are no longer available in the requested quantity.');
            }
        }
    }

    /**
     * Return reserved stock when a charge fails after the order was created. Keeps an
     * audit row so the reserve/release pair is traceable.
     *
     * @param  array<int, LineItem>  $lineItems
     */
    public function releaseStock(Order $order, array $lineItems): void
    {
        foreach ($lineItems as $line) {
            $holder = $this->inventory->holderFor((int) $line['product_id'], $line['product_variant_id'] ?? null);

            if ($holder !== null && $holder->tracksInventory()) {
                $this->inventory->adjust($holder, $line['quantity'], 'payment_failed_release', $order);
            }
        }
    }

    /**
     * Charge the order total through the given gateway. $paymentExtra carries the
     * gateway-specific token/id (e.g. ['token' => ...] for Stripe, ['payment_id' => ...]
     * for PayPal); order_id and customer_email are always included.
     */
    public function capturePayment(Order $order, string $gateway, array $paymentExtra): array
    {
        return PaymentGatewayFactory::create($gateway)->processPayment((float) $order->total_amount, [
            'order_id' => $order->id,
            'customer_email' => $order->customer_email,
        ] + $paymentExtra);
    }
}
