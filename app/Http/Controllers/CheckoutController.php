<?php

namespace App\Http\Controllers;

use App\Exceptions\CheckoutException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Notifications\OrderConfirmationNotification;
use App\Services\CheckoutService;
use App\Services\ShippingService;
use App\Services\TaxCalculator;
use App\Services\ViesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Validator;

class CheckoutController extends Controller
{
    protected $shippingService;

    protected $taxCalculator;

    protected $checkoutService;

    protected $viesService;

    public function __construct(ShippingService $shippingService, TaxCalculator $taxCalculator, CheckoutService $checkoutService, ViesService $viesService)
    {
        $this->shippingService = $shippingService;
        $this->taxCalculator = $taxCalculator;
        $this->checkoutService = $checkoutService;
        $this->viesService = $viesService;
    }

    public function initiateCheckout(Request $request)
    {
        $isGuest = Session::get('is_guest', false);
        $cart = Session::get('cart', []);

        if (empty($cart)) {
            return redirect()->route('products.index')
                ->with('error', 'Your cart is empty');
        }

        $shippingMethods = $this->shippingService->getAvailableShippingMethods();

        // Calculate subtotal and whether physical products exist
        $subtotal = collect($cart)->sum(function ($item) {
            return $item['price'] * $item['quantity'];
        });

        $hasPhysicalProducts = $this->hasPhysicalProducts($cart);

        return view('checkout.checkout', [
            'cart' => $cart,
            'shippingMethods' => $shippingMethods,
            'isGuest' => $isGuest,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'hasPhysicalProducts' => $hasPhysicalProducts,
        ]);
    }

    /**
     * Fetch live carrier rates for the current cart + destination and persist each as
     * a session-scoped quote. Returns the quotes (with their ids) for the buyer to
     * select. Empty `rates` = no live carrier configured/reachable → the page keeps
     * its flat DB methods.
     */
    public function shippingRates(Request $request)
    {
        $validated = $request->validate([
            'country' => 'required|string|size:2',
            'state' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'street1' => 'nullable|string|max:255',
        ]);

        $cart = Session::get('cart', []);
        if (empty($cart)) {
            return response()->json(['rates' => [], 'error' => 'Your cart is empty.'], 422);
        }

        $to = [
            'country' => $validated['country'],
            'state' => $validated['state'] ?? null,
            'city' => $validated['city'] ?? null,
            'zip' => $validated['postal_code'] ?? null,
            'street1' => $validated['street1'] ?? null,
        ];

        $quotes = $this->shippingService->quoteLiveRates(
            $cart,
            $to,
            $request->session()->getId(),
            auth()->id(),
        );

        return response()->json([
            'rates' => $quotes->map(fn ($q) => [
                'id' => $q->id,
                'carrier' => $q->carrier,
                'service' => $q->service,
                'amount' => (float) $q->amount,
                'currency' => $q->currency,
                'delivery_days' => $q->delivery_days,
            ])->all(),
        ]);
    }

    public function processCheckout(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'has_physical_products' => 'sometimes|in:0,1',
            'shipping_address' => 'required_if:has_physical_products,1|string',
            // A physical order ships on either a flat method OR a live-rate quote;
            // the "one of them is present" check is enforced below (needs the cart).
            'shipping_method_id' => 'nullable|exists:shipping_methods,id',
            'shipping_quote_id' => 'nullable|integer|exists:shipping_quotes,id',
            // Structured address drives tax (country) and is required for physical orders.
            // Required for every order — a digital-only order still needs the buyer's
            // country so digital goods are taxed (VAT-on-digital), not just physical ones.
            'country' => 'required|string|size:2',
            'state' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'vat_number' => 'nullable|string|max:20',
            'payment_method' => 'required|string',
            'recipient_name' => 'required_if:dropship,on|string',
            'recipient_email' => 'required_if:dropship,on|email',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $cart = Session::get('cart', []);

        if (empty($cart)) {
            return redirect()->route('products.index')
                ->with('error', 'Your cart is empty');
        }

        // Verify inventory before processing
        foreach ($cart as $productId => $item) {
            $product = Product::find($productId);
            if (! $product || $product->inventory_count < $item['quantity']) {
                return redirect()->back()->with('error', 'Some items in your cart are no longer available in the requested quantity.');
            }
        }

        // Calculate total amount
        $subtotal = collect($cart)->sum(function ($item) {
            return $item['price'] * $item['quantity'];
        });

        $shippingCost = 0;
        $shippingCarrier = null;
        $shippingServiceName = null;
        $shippingQuoteId = null;
        if ($this->hasPhysicalProducts($cart)) {
            if ($request->filled('shipping_quote_id')) {
                // Live carrier rate. Bill the STORED quote amount — an API-quoted price
                // can't be recomputed, and a client-posted price must never be trusted.
                // resolveQuote scopes to this session and rejects expired quotes.
                $quote = $this->shippingService->resolveQuote(
                    (int) $request->input('shipping_quote_id'),
                    $request->session()->getId(),
                    auth()->id(),
                );
                if (! $quote) {
                    return redirect()->back()->withInput()
                        ->with('error', 'Your selected shipping rate is no longer valid. Please choose shipping again.');
                }
                $premium = $request->has('dropship') ? (float) config('shipping.drop_shipping_premium', 2.00) : 0.0;
                $shippingCost = round((float) $quote->amount + $premium, 2);
                $shippingCarrier = $quote->carrier;
                $shippingServiceName = $quote->service;
                $shippingQuoteId = $quote->id;
            } elseif ($request->filled('shipping_method_id')) {
                $shippingMethod = ShippingMethod::find($request->shipping_method_id);
                $shippingCost = $request->has('dropship') ?
                    $this->shippingService->calculateDropShippingCost($shippingMethod, $cart, $request->shipping_address) :
                    $this->shippingService->calculateShippingCost($shippingMethod, $cart, $request->shipping_address);
            } else {
                return redirect()->back()->withInput()
                    ->with('error', 'Please choose a shipping method.');
            }
        }

        // Re-validate and RE-COMPUTE the coupon discount against the LIVE cart.
        // The session only cached a dollar figure captured at apply-time; trusting it
        // lets a shopper apply a coupon on a large cart, shrink the cart, then check out
        // with a stale (over-large) discount — driving the total negative into a free
        // order that skips payment, or under-charging. Recomputing here also drops a
        // coupon that has since expired, hit its usage limit, or fallen below its
        // minimum spend, none of which checkout previously re-checked.
        //
        // ponytail: this closes the money bug + expiry/min-spend/over-limit TOCTOU. A
        // simultaneous double-submit of a max_uses=1 coupon can still slip past (both
        // read the usage count before either order commits); close that with a
        // lockForUpdate on the coupon row inside the reservation transaction if abuse
        // shows up — left out here as it's unreachable to unit test and low-severity.
        $discountAmount = 0;
        $couponCode = null;
        $couponData = Session::get('coupon');
        if ($couponData && ! empty($couponData['code'])) {
            $coupon = $this->checkoutService->resolveCouponDiscount($couponData['code'], $subtotal);
            if ($coupon['valid']) {
                $discountAmount = $coupon['discount'];
                $couponCode = $coupon['code'];
            } else {
                Session::forget('coupon');
            }
        }

        // Intra-EU B2B supply with a VIES-valid VAT number is zero-rated (the buyer
        // accounts for VAT under the reverse charge); otherwise tax normally.
        $vatNumber = $this->viesService->normalise($request->input('vat_number'));
        $reverseCharge = $this->viesService->reverseChargeApplies($vatNumber);

        if ($reverseCharge) {
            $taxAmount = 0;
            $taxLines = [];
        } else {
            // Calculate tax with the structured shipping address, honouring per-product
            // tax class + exemptions and returning a tax_lines breakdown. Any cart
            // discount is distributed pro-rata so tax lands on the post-discount amount.
            $taxAddress = [
                'country' => $request->input('country'),
                'state' => $request->input('state'),
                'city' => $request->input('city'),
                'postal_code' => $request->input('postal_code'),
            ];
            // Every line goes over, missing product included: those are not taxed
            // — nothing says at what rate — but they are part of the cart the
            // coupon was given against, so the calculator counts them when it
            // spreads the discount.
            $taxItems = [];
            foreach ($cart as $productId => $item) {
                $taxItems[] = [
                    'product' => Product::find($productId),
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ];
            }
            $taxResult = $this->taxCalculator->calculateCartTax($taxItems, $taxAddress, $shippingCost, $discountAmount);
            $taxAmount = $taxResult['total'];
            $taxLines = $taxResult['lines'];
        }

        // Floor at 0 — a discount can zero an order but must never make it negative
        // (which would skip payment yet still mark the order paid).
        $totalAmount = max(0, $subtotal - $discountAmount + $shippingCost + $taxAmount);

        // Create the order and RESERVE stock atomically, before charging.
        // If any line can't be reserved (e.g. a concurrent buyer took the last
        // unit), the whole transaction rolls back and no payment is taken — this
        // is what prevents charging a customer for stock we can't fulfil.
        $lineItems = [];
        foreach ($cart as $productId => $item) {
            $lineItems[] = ['product_id' => $productId, 'quantity' => $item['quantity'], 'price' => $item['price']];
        }

        $order = null;
        try {
            DB::transaction(function () use (&$order, $lineItems, $request, $totalAmount, $shippingCost, $taxAmount, $taxLines, $discountAmount, $couponCode, $shippingCarrier, $shippingServiceName, $shippingQuoteId, $vatNumber, $reverseCharge) {
                // Close the coupon usage-limit race under a row lock before creating the order.
                $this->checkoutService->assertCouponAvailable($couponCode);

                $order = Order::create([
                    'user_id' => auth()->id(),
                    'customer_email' => $request->email,
                    'shipping_address' => $request->shipping_address,
                    // Buyer country VAT was charged against — drives the OSS/MOSS report.
                    'billing_country' => strtoupper((string) $request->input('country')),
                    'vat_number' => $vatNumber,
                    'reverse_charge' => $reverseCharge,
                    // A live-rate quote drives the cost; don't also record a flat method.
                    'shipping_method_id' => $shippingQuoteId ? null : $request->shipping_method_id,
                    'shipping_carrier' => $shippingCarrier,
                    'shipping_service' => $shippingServiceName,
                    'shipping_quote_id' => $shippingQuoteId,
                    'payment_method' => $request->payment_method,
                    'total_amount' => $totalAmount,
                    'shipping_cost' => $shippingCost,
                    'tax_amount' => $taxAmount,
                    'tax_lines' => $taxLines,
                    'discount_amount' => $discountAmount,
                    'coupon_code' => $couponCode,
                    'status' => 'pending',
                    'is_dropshipped' => $request->has('dropship'),
                    'recipient_name' => $request->recipient_name,
                    'recipient_email' => $request->recipient_email,
                    'gift_message' => $request->gift_message,
                ]);

                // Reserve stock atomically before charging (shared with headless checkout).
                $this->checkoutService->reserveStock($order, $lineItems);
            });
        } catch (CheckoutException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        /** @var Order $order */

        // Charge payment. Stock is already reserved, so we never charge for stock
        // we can't fulfil. If the charge fails, release the reservation.
        if ($totalAmount > 0) {
            if ($request->payment_method === 'stripe' && $request->has('stripeToken')) {
                $paymentResult = $this->checkoutService->capturePayment($order, 'stripe', ['token' => $request->stripeToken]);
            } elseif ($request->payment_method === 'paypal' && $request->has('paypal_payment_id')) {
                $paymentResult = $this->checkoutService->capturePayment($order, 'paypal', ['payment_id' => $request->paypal_payment_id]);
            } else {
                $this->checkoutService->releaseStock($order, $lineItems);
                $order->transitionTo(Order::STATUS_FAILED, notes: 'Invalid payment information');

                return redirect()->back()
                    ->with('error', 'Invalid payment information. Please try again.');
            }

            if (! $paymentResult['success']) {
                $this->checkoutService->releaseStock($order, $lineItems);
                $order->transitionTo(Order::STATUS_FAILED, notes: 'Payment failed: '.($paymentResult['error'] ?? 'unknown'));

                return redirect()->back()
                    ->with('error', 'Payment failed: '.($paymentResult['error'] ?? 'Please try again.'));
            }
        }

        // Persist the gateway charge id so a later refund has something to void.
        if (isset($paymentResult['transaction_id'])) {
            $order->update(['transaction_id' => $paymentResult['transaction_id']]);
        }

        $order->transitionTo(Order::STATUS_PAID, notes: 'Payment captured');

        // Authorize the (possibly guest) buyer to view this order's confirmation page.
        // showConfirmation checks this session list so the confirmation is not an open
        // IDOR — both the normal and the dropship-error redirect below run after this.
        Session::push('recent_order_ids', $order->id);

        // Send order confirmation email
        Notification::route('mail', $order->customer_email)
            ->notify(new OrderConfirmationNotification($order));

        // If dropshipping, queue supplier order placement (shared with headless checkout).
        if ($order->is_dropshipped) {
            $supplierId = $request->input('supplier_id', 'dropxl');
            if (! $this->checkoutService->queueDropship($order, $supplierId)) {
                return redirect()->route('checkout.confirmation', ['order' => $order->id])
                    ->with('warning', 'Order placed but an error occurred while queuing the supplier order. Our team will follow up.');
            }
        }

        // Issue download tokens for any downloadable lines.
        $this->checkoutService->grantDownloads($order);

        // Clear cart and coupon
        Session::forget('cart');
        Session::forget('coupon');

        return redirect()->route('checkout.confirmation', ['order' => $order->id])
            ->with('success', 'Order placed successfully!');
    }

    public function showConfirmation(Request $request, Order $order)
    {
        // The confirmation page exposes full order PII, so it must not be an open
        // IDOR. Allow the order's owner, or a (possibly guest) buyer who placed it
        // this session — nobody else can enumerate arbitrary order ids.
        $ownsOrder = $order->user_id !== null && $request->user()?->id === $order->user_id;
        $justPlaced = in_array($order->id, Session::get('recent_order_ids', []), true);

        abort_unless($ownsOrder || $justPlaced, 403);

        return view('checkout.confirmation', [
            'order' => $order,
        ]);
    }

    public function guestCheckout(Request $request)
    {
        $cart = Session::get('cart', []);

        // Store cart in guest session
        Session::put('guest_cart', $cart);
        Session::put('is_guest', true);

        return redirect()->route('checkout.initiate');
    }

    private function hasPhysicalProducts($cart)
    {
        foreach ($cart as $item) {
            if (! $item['is_downloadable']) {
                return true;
            }
        }

        return false;
    }
}
