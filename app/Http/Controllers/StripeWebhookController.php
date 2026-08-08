<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Notifications\OrderConfirmationNotification;
use App\Notifications\OrderRefundedNotification;
use App\Services\StoreContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    /**
     * Verified Stripe webhook. Reconciles order state with Stripe for events that
     * may happen out of band with our synchronous checkout — a charge captured
     * async, or (most usefully) a refund issued from the Stripe dashboard.
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature') ?? '',
                (string) config('services.stripe.webhook.secret')
            );
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        $charge = $event->data->object;

        // Across every store, deliberately. Stripe posts to one configured
        // endpoint, so this request's host resolves to whichever store happens to
        // own that hostname — not to the store the charge belongs to. Scoped, a
        // payment confirmation for any other store would find no order, take the
        // `null` branch, and return 200: money captured, order left pending, and
        // nothing anywhere saying so. The charge id is the authority here.
        StoreContext::acrossAllStores(function () use ($event, $charge) {
            match ($event->type) {
                'charge.succeeded' => $this->markPaid($charge),
                'charge.failed' => $this->markFailed($charge),
                'charge.refunded' => $this->reconcileRefund($charge),
                default => null, // acknowledge and ignore everything else
            };
        });

        return response()->json(['received' => true]);
    }

    private function orderForCharge($charge): ?Order
    {
        return isset($charge->id) ? Order::where('transaction_id', $charge->id)->first() : null;
    }

    private function markPaid($charge): void
    {
        $order = $this->orderForCharge($charge);

        // Only when the webhook itself moves the order to paid (an async capture
        // the synchronous checkout didn't already confirm) do we email — otherwise
        // we'd double-send the confirmation the checkout already sent.
        if ($this->transition($order, Order::STATUS_PAID, 'Stripe webhook: charge.succeeded')) {
            Notification::route('mail', $order->customer_email)->notify(new OrderConfirmationNotification($order));
        }
    }

    private function markFailed($charge): void
    {
        $this->transition($this->orderForCharge($charge), Order::STATUS_FAILED, 'Stripe webhook: charge.failed');
    }

    private function reconcileRefund($charge): void
    {
        $order = $this->orderForCharge($charge);
        if (! $order) {
            return;
        }

        // Stripe's amount_refunded (in cents) is authoritative. Setting it (rather
        // than incrementing) keeps this idempotent across webhook retries and
        // refunds already recorded in-app.
        $refunded = (float) (($charge->amount_refunded ?? 0) / 100);
        $fully = $refunded >= (float) $order->total_amount;

        $order->update([
            'refund_total' => $refunded,
            'fully_refunded' => $fully,
            'partially_refunded' => ! $fully && $refunded > 0,
        ]);

        $target = $fully ? Order::STATUS_REFUNDED : Order::STATUS_PARTIALLY_REFUNDED;

        // Notify only when this is a newly-recognised refund (e.g. issued from the
        // Stripe dashboard). An in-app refund already emailed and already moved the
        // order, so the transition is skipped here and no second email is sent.
        if ($this->transition($order, $target, 'Stripe webhook: charge.refunded')) {
            Notification::route('mail', $order->customer_email)->notify(new OrderRefundedNotification($order, $refunded));
        }
    }

    /**
     * Move the order through the state machine only if the transition is legal —
     * webhooks are at-least-once and may arrive for an order already in the target
     * (or a later) state, so an illegal transition is silently skipped, never thrown.
     * Returns whether the transition actually happened.
     */
    private function transition(?Order $order, string $status, string $notes): bool
    {
        if ($order && in_array($status, Order::TRANSITIONS[$order->status] ?? [], true)) {
            $order->transitionTo($status, notes: $notes);

            return true;
        }

        return false;
    }
}
