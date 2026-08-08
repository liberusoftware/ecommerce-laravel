<?php

use App\Http\Controllers\AccountDataExportController;
use App\Http\Controllers\AccountErasureController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\Frontend\ProductCategoryController;
use App\Http\Controllers\Frontend\ProductCollectionController;
use App\Http\Controllers\Frontend\ProductController;
use App\Http\Controllers\Frontend\ProductTagController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\OrderHistoryController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PaypalPaymentController;
use App\Http\Controllers\PaypalWebhookController;
use App\Http\Controllers\RatingController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SiteSettingController;
use App\Http\Controllers\StripePaymentController;
use App\Http\Controllers\StripeWebhookController; // New controller for cart
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\WishlistController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use JoelButcher\Socialstream\Socialstream;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Kubernetes liveness/readiness probe endpoint
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();

        return response()->json(['status' => 'ok', 'db' => 'connected'], 200);
    } catch (Throwable $e) {
        return response()->json(['status' => 'degraded', 'db' => 'unavailable'], 503);
    }
})->name('health');

Route::get('/', [HomeController::class, 'index'])->name('home');

// Product routes
Route::get('/wishlist/shared/{shareToken}', [WishlistController::class, 'sharedWishlist'])->name('wishlist.shared');
Route::middleware('auth')->group(function () {
    Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('/wishlist/add/{product}', [WishlistController::class, 'add'])->name('wishlist.add');
    Route::delete('/wishlist/remove/{product}', [WishlistController::class, 'remove'])->name('wishlist.remove');
    Route::post('/wishlist/share', [WishlistController::class, 'share'])->name('wishlist.share');
});
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/search', [ProductController::class, 'search'])->name('products.search')->middleware('throttle:30,1');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
Route::post('/products/{product}/notify-me', [ProductController::class, 'notifyMe'])->name('products.notify-me')->middleware('throttle:10,1');

// Category routes
Route::get('/categories', [ProductCategoryController::class, 'index'])->name('categories.index');
Route::get('/categories/{category}', [ProductCategoryController::class, 'show'])->name('categories.show');
Route::get('/categories/{category}/products', [ProductCategoryController::class, 'products'])->name('categories.products');

// Collection routes
Route::get('/collections', [ProductCollectionController::class, 'index'])->name('collections.index');
Route::get('/collections/{collection}', [ProductCollectionController::class, 'show'])->name('collections.show');
Route::get('/collections/{collection}/products', [ProductCollectionController::class, 'products'])->name('collections.products');

// Tag routes
Route::get('/tags', [ProductTagController::class, 'index'])->name('tags.index');
Route::get('/tags/{tag}', [ProductTagController::class, 'show'])->name('tags.show');

// Checkout routes
Route::get('/checkout', [CheckoutController::class, 'initiateCheckout'])->name('checkout.initiate');
// Throttled: guest checkout captures payment with a posted card token, so an
// unbounded route is a card-testing / BIN-enumeration oracle on our Stripe account.
Route::post('/checkout/process', [CheckoutController::class, 'processCheckout'])->name('checkout.process')->middleware('throttle:10,1');
// Live carrier rates for the current cart + destination — each is persisted as a
// session-scoped quote the buyer then selects by id (server bills the stored amount).
Route::post('/checkout/shipping-rates', [CheckoutController::class, 'shippingRates'])->name('checkout.shipping-rates');
Route::get('/checkout/confirmation/{order}', [CheckoutController::class, 'showConfirmation'])->name('checkout.confirmation');

// Shipping routes — store-wide config, admin-only (auth here, role checked in the controller)
Route::middleware('auth')->group(function () {
    Route::get('/shipping', [ShippingController::class, 'index'])->name('shipping.index');
    Route::post('/shipping', [ShippingController::class, 'store'])->name('shipping.store');
    Route::put('/shipping/{shippingMethod}', [ShippingController::class, 'update'])->name('shipping.update');
    Route::delete('/shipping/{shippingMethod}', [ShippingController::class, 'destroy'])->name('shipping.destroy');
});

// Order history routes
Route::middleware('auth')->group(function () {
    Route::get('/orders', [OrderHistoryController::class, 'index'])->name('orders.index');
    Route::get('/orders/{id}', [OrderHistoryController::class, 'show'])->name('orders.show');

    // GDPR right-of-access: download all of your own personal data as JSON.
    Route::get('/account/data-export', AccountDataExportController::class)->name('account.data-export');

    // GDPR right-to-erasure: anonymise your account (orders retained, PII scrubbed).
    Route::delete('/account', AccountErasureController::class)->name('account.erase');
});

Route::middleware('auth')->prefix('payment_methods')->group(function () {
    Route::get('/', [PaymentMethodController::class, 'index'])->name('payment_methods.index');
    Route::post('/store', [PaymentMethodController::class, 'addPaymentMethod'])->name('payment_methods.store');
    Route::get('/edit/{id}', [PaymentMethodController::class, 'editPaymentMethod'])->name('payment_methods.edit');
    Route::post('/update/{id}', [PaymentMethodController::class, 'editPaymentMethod'])->name('payment_methods.update');
    Route::delete('/destroy/{id}', [PaymentMethodController::class, 'deletePaymentMethod'])->name('payment_methods.destroy');
    Route::post('/set_default/{id}', [PaymentMethodController::class, 'setDefaultPaymentMethod'])->name('payment_methods.setDefault');
});

// Stripe webhook — unauthenticated + CSRF-exempt (verified by signature in the controller)
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle'])->name('stripe.webhook');

// PayPal subscription webhook — unauthenticated + CSRF-exempt (signature verified in
// the controller). PayPal drives subscription lifecycle status (activated/cancelled/…)
// out of band; this reconciles it onto the local PaypalSubscription record.
Route::post('/paypal/webhook', [PaypalWebhookController::class, 'handle'])->name('paypal.webhook');

// Stripe subscription mutations act only on the current user ($request->user()),
// so an anonymous request would deref null and 500. Require auth.
Route::middleware('auth')->group(function () {
    Route::post('/stripe/subscription', [StripePaymentController::class, 'createSubscription'])->name('stripe.subscription.create');
    Route::patch('/stripe/subscription', [StripePaymentController::class, 'updateSubscription'])->name('stripe.subscription.update');
    Route::delete('/stripe/subscription', [StripePaymentController::class, 'cancelSubscription'])->name('stripe.subscription.cancel');
});

// Plan listing touches no user data — stays public (pricing page).
Route::get('/subscriptions', [SubscriptionController::class, 'viewAvailableSubscriptions'])->name('subscriptions.view');

// Subscription management all runs on Auth::user() — same null-deref risk, require auth.
Route::middleware('auth')->group(function () {
    Route::post('/subscription', [SubscriptionController::class, 'subscribeToPlan'])->name('subscription.create');
    Route::patch('/subscription/change', [SubscriptionController::class, 'changePlan'])->name('subscription.change-plan');
    Route::delete('/subscription/cancel', [SubscriptionController::class, 'cancelSubscription'])->name('subscription.cancel');
});

// PayPal one-time payment + subscription mutations act on caller-supplied ids
// (a PayPal order id to capture / a subscriptionId) against the merchant's live
// PayPal credentials — require auth so an anonymous request can't trigger a capture
// or update/cancel someone else's subscription.
// createSubscription now persists a PaypalSubscription owned by the caller, and the
// webhook keeps its status in sync. ponytail: update/cancel still act on a
// caller-supplied subscriptionId without checking that persisted ownership — a
// follow-up should scope them to the caller's own PaypalSubscription rows.
Route::middleware('auth')->group(function () {
    Route::post('/paypal/payment', [PaypalPaymentController::class, 'createOneTimePayment'])->name('paypal.payment.create');
    Route::post('/paypal/subscription', [PaypalPaymentController::class, 'createSubscription'])->name('paypal.subscription.create');
    Route::patch('/paypal/subscription/update', [PaypalPaymentController::class, 'updateSubscription'])->name('paypal.subscription.update');
    Route::delete('/paypal/subscription/cancel', [PaypalPaymentController::class, 'cancelSubscription'])->name('paypal.subscription.cancel');
});

// Cart routes
Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
Route::post('/cart/add/{product}', [CartController::class, 'add'])->name('cart.add');
Route::put('/cart/update/{productId}', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart/remove/{productId}', [CartController::class, 'remove'])->name('cart.remove');
Route::delete('/cart/clear', [CartController::class, 'clear'])->name('cart.clear');
// Throttled: distinguishable valid/invalid responses make this brute-forceable to
// enumerate discount codes; cap attempts per IP.
Route::post('/cart/apply-coupon', [CartController::class, 'applyCoupon'])->name('cart.apply-coupon')->middleware('throttle:10,1');
Route::delete('/cart/remove-coupon', [CartController::class, 'removeCoupon'])->name('cart.remove-coupon');

// Ratings and reviews — reads are public; writes require login (approve is admin-only, gated in the controller)
Route::get('/product/{product}/reviews', [ReviewController::class, 'show'])->name('reviews.show');
Route::middleware('auth')->group(function () {
    Route::post('/reviews', [ReviewController::class, 'store'])->name('reviews.store');
    Route::post('/reviews/approve/{id}', [ReviewController::class, 'approve'])->name('reviews.approve');
    Route::post('/reviews/{id}/vote', [ReviewController::class, 'vote'])->name('reviews.vote');
});

Route::get('/product/{product}/ratings/average', [RatingController::class, 'calculateAverageRating'])->name('ratings.average');
Route::post('/ratings', [RatingController::class, 'store'])->middleware('auth')->name('ratings.store');

Route::middleware('auth')->group(function () {
    Route::get('/download/{product}', [DownloadController::class, 'generateSecureLink'])->name('download.generate-link');
    Route::get('/download/file/{product}', [DownloadController::class, 'serveFile'])->name('download.serve-file');

    // Invoice routes
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('/invoices/{id}', [InvoiceController::class, 'show'])->name('invoices.show');
});

// Site settings are store-wide config, admin-only (auth here, role checked in the controller)
Route::middleware('auth')->group(function () {
    Route::get('/site-settings', [SiteSettingController::class, 'index'])->name('site_settings.index');
    Route::get('/site-settings/{id}/edit', [SiteSettingController::class, 'edit'])->name('site_settings.edit');
    Route::post('/site-settings/{id}', [SiteSettingController::class, 'update'])->name('site_settings.update');
});

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.xml');

// Inventory routes
Route::post('/inventory/adjust', [InventoryController::class, 'adjustInventory'])->middleware('auth')->name('inventory.adjust');

// Pages
// TODO: implement CMS features for page and form editing
Route::get('/contact', [ContactController::class, 'show'])->name('contact');
// Public and unauthenticated, so it is a spam-relay target: honeypot in the
// controller, throttle here. Matches the convention used by apply-coupon and notify-me.
Route::post('/contact', [ContactController::class, 'send'])
    ->middleware('throttle:5,1')
    ->name('contact.send');
Route::view('/about', 'about')->name('about');
Route::view('/shop', 'shop')->name('shop');

Route::view('/account', 'account')->middleware('auth')->name('account');

// Blog routes

// Chat routes
Route::prefix('chat')->group(function () {
    // Public chat: unauthenticated. Throttle the writes so a guest can't loop
    // start->message to flood the conversation/message tables + the agent queue.
    Route::post('/start', [ChatController::class, 'start'])->name('chat.start')->middleware('throttle:15,1');
    Route::get('/session/{sessionId}', [ChatController::class, 'getBySession'])->name('chat.session');
    Route::post('/{conversationId}/message', [ChatController::class, 'sendMessage'])->name('chat.message')->middleware('throttle:30,1');
    Route::get('/{conversationId}/messages', [ChatController::class, 'getMessages'])->name('chat.messages');
    Route::post('/{conversationId}/close', [ChatController::class, 'close'])->name('chat.close')->middleware('throttle:15,1');
    Route::post('/{conversationId}/rating', [ChatController::class, 'submitRating'])->name('chat.rating')->middleware('throttle:15,1');

    // Agent routes
    Route::middleware(['auth'])->group(function () {
        Route::get('/agent/conversations', [ChatController::class, 'agentConversations'])->name('chat.agent.conversations');
        Route::post('/{conversationId}/assign', [ChatController::class, 'assignAgent'])->name('chat.assign');
        Route::get('/agent/next', [ChatController::class, 'nextQueued'])->name('chat.agent.next');
    });
});

if (class_exists(Socialstream::class)) {
    require __DIR__.'/socialstream.php';
}
