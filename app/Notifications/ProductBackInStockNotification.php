<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProductBackInStockNotification extends Notification
{
    use Queueable;

    public function __construct(public Product $product) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $productName = $this->product->name;

        return (new MailMessage)
            ->subject('Product Back in Stock: '.$productName)
            ->greeting('Good news!')
            ->line('The product you were waiting for is now back in stock:')
            ->line("**{$productName}**")
            ->action('View Product', route('products.show', $this->product))
            ->line('Hurry! Limited stock available.');
    }
}
