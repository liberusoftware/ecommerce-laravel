<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification
{
    use Queueable;

    protected $product;

    /** Set when the stock running low is one variant's. */
    protected ?ProductVariant $variant;

    public function __construct(Product $product, ?ProductVariant $variant = null)
    {
        $this->product = $product;
        $this->variant = $variant;
    }

    private function name(): string
    {
        return $this->product->name.($this->variant ? ' ('.$this->variant->label().')' : '');
    }

    private function stock(): int
    {
        return (int) ($this->variant ?? $this->product)->inventory_count;
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Low Stock Alert')
            ->line('The product "'.$this->name().'" is running low on stock.')
            ->line('Current stock: '.$this->stock())
            ->line('Low stock threshold: '.$this->product->low_stock_threshold)
            ->action('View Product', url('/admin/products/'.$this->product->id));
    }

    public function toArray($notifiable)
    {
        return [
            'product_id' => $this->product->id,
            'product_variant_id' => $this->variant?->id,
            'product_name' => $this->name(),
            'current_stock' => $this->stock(),
            'low_stock_threshold' => $this->product->low_stock_threshold,
        ];
    }
}
