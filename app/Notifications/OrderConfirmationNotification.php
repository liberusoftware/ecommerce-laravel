<?php

namespace App\Notifications;

use App\Models\Order;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderConfirmationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $order;

    /**
     * Create a new notification instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;

        $mailMessage = (new MailMessage)
            ->subject('Order Confirmation #'.$order->id)
            ->greeting('Thank you for your order!')
            ->line('Your order has been successfully placed.')
            ->line('Order Number: #'.$order->id)
            ->line('Total Amount: '.Money::format($order->total_amount, $order->currency_code));

        if ($order->shipping_address) {
            $mailMessage->line('Shipping Address: '.$order->shipping_address);
        }

        if ($order->shippingMethod) {
            $mailMessage->line('Shipping Method: '.$order->shippingMethod->name)
                ->line('Estimated Delivery: '.$order->shippingMethod->estimated_delivery_time);
        }

        // Add order items
        $mailMessage->line('**Order Items:**');
        foreach ($order->items as $item) {
            $options = $item->optionLabels($order->currency_code);
            $mailMessage->line('- '.$item->displayName().($options ? ' ['.implode('; ', $options).']' : '').' (Qty: '.$item->quantity.') - '.Money::format($item->price * $item->quantity, $order->currency_code));
        }

        // Check for downloadable products
        $hasDownloadable = $order->items->contains(function ($item) {
            return $item->product?->is_downloadable ?? false;
        });

        if ($hasDownloadable) {
            $mailMessage->line('Your digital products will be available for download in your account.')
                ->action('View Downloads', route('orders.show', $order->id));
        }

        $mailMessage->line('We will send you another email when your order ships.')
            ->action('View Order Details', route('orders.show', $order->id))
            ->line('Thank you for shopping with us!');

        return $mailMessage;
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'total_amount' => $this->order->total_amount,
        ];
    }
}
