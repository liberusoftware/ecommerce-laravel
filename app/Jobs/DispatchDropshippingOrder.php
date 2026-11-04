<?php

namespace App\Jobs;

use App\Models\Order;
use App\Notifications\SupplierFailureNotification;
use App\Services\DropshippingService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

class DispatchDropshippingOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $orderId;

    public $supplierId;

    /** Number of times the queued job may be attempted. */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(int $orderId, string $supplierId = 'dropxl')
    {
        $this->orderId = $orderId;
        $this->supplierId = $supplierId;
    }

    /**
     * Execute the job.
     */
    public function handle(DropshippingService $dropshippingService): void
    {
        $order = Order::with(['items.product', 'items.variant'])->find($this->orderId);

        if (! $order) {
            return;
        }

        // At-least-once queue: if this order was already placed with the supplier,
        // a retry must not place it again (double fulfilment / double cost).
        if ($order->supplier_order_reference) {
            return;
        }

        // The order may have been refunded/cancelled while sitting in the queue —
        // only dispatch orders still awaiting the supplier.
        if ($order->status !== Order::STATUS_SUPPLIER_QUEUED) {
            return;
        }

        // Build supplier payload
        $orderData = [
            'reference' => 'order-'.$order->id,
            'customer_name' => $order->shipping_name ?: $order->user?->name ?: $order->customer_email,
            'customer_email' => $order->customer_email,
            'recipient_name' => $order->recipient_name,
            'recipient_email' => $order->recipient_email,
            'shipping_address' => $order->shipping_address,
            'shipping_city' => $order->shipping_city,
            'shipping_state' => $order->shipping_state,
            'shipping_postal_code' => $order->shipping_postal_code,
            'shipping_country' => $order->billing_country,
            'shipping_method' => $order->shipping_method_id,
            'shipping_cost' => $order->shipping_cost ?? 0,
            'gift_message' => $order->gift_message,
            'items' => [],
        ];

        // Where products name their supplier, this supplier gets only its own lines.
        $items = $order->items->contains(fn ($item) => $item->product?->isDropshipped())
            ? $order->items->filter(fn ($item) => $item->product?->supplier_id === $this->supplierId)
            : $order->items;

        foreach ($items as $item) {
            $orderData['items'][] = [
                'product_id' => $item->product_id,
                'sku' => $item->sku ?? $item->product?->sku,
                'supplier_variant_id' => $item->variant?->supplier_variant_id,
                'quantity' => $item->quantity,
                'price' => $item->price,
            ];
        }

        try {
            $result = $dropshippingService->placeOrder($this->supplierId, $orderData);

            // Persist supplier response (and the reference — its presence is the
            // idempotency key that stops a retry re-placing the order).
            $order->supplier_id = $this->supplierId;
            $order->supplier_order_reference = $result['data']['reference'] ?? ($result['data']['id'] ?? null);
            $order->supplier_response = $result['data'] ?? ($result['error'] ?? $result);
            $order->save();

            if ($result['success']) {
                $this->transition($order, Order::STATUS_PROCESSING, "Supplier order placed ({$this->supplierId})");
            } else {
                $this->transition($order, Order::STATUS_SUPPLIER_FAILED, 'Supplier rejected the order');
                $this->notifyFailure($order, $result['message'] ?? 'Unknown');
            }
        } catch (Exception $e) {
            // Transient failure (network/timeout). Do NOT mark supplier_failed here
            // — that would trip the still-queued guard and skip the retry. Let the
            // exception propagate so the queue retries; failed() handles exhaustion.
            throw $e;
        }
    }

    /**
     * The job's retries are exhausted — record the failure so the order isn't
     * left stuck in supplier_queued.
     */
    public function failed(?Throwable $e): void
    {
        $order = Order::find($this->orderId);

        if ($order && ! $order->supplier_order_reference && $order->status === Order::STATUS_SUPPLIER_QUEUED) {
            $order->supplier_response = ['exception' => $e?->getMessage()];
            $order->save();
            $this->transition($order, Order::STATUS_SUPPLIER_FAILED, 'Supplier dispatch failed after retries');
            $this->notifyFailure($order, $e?->getMessage() ?? 'Unknown error');
        }
    }

    /**
     * Route status changes through the state machine (enforces the transition map
     * + writes an audit row). Skips silently if the order has since moved to a
     * state from which the target is illegal (e.g. refunded) rather than throwing.
     */
    private function transition(Order $order, string $status, string $notes): void
    {
        if (in_array($status, Order::TRANSITIONS[$order->status] ?? [], true)) {
            $order->transitionTo($status, notes: $notes);
        }
    }

    private function notifyFailure(Order $order, string $message): void
    {
        Notification::route('mail', config('mail.from.address'))
            ->notify(new SupplierFailureNotification("Dropshipping order placement failed for order {$order->id}: {$message}"));
    }
}
