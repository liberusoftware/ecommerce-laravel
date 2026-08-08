<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;
    use IsStoreScoped;

    protected $table = 'invoices';

    protected $fillable = [
        'order_id',
        'customer_id',
        'invoice_date',
        'total_amount',
        'payment_status',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
    ];

    /**
     * Generate (once) the invoice for a paid order: resolve the customer via the
     * User<->Customer identity link — or create one from the guest email — and copy
     * the order's line items onto the invoice. Idempotent per order_id.
     */
    public static function generateForOrder(Order $order): self
    {
        $order->loadMissing('items');

        $invoice = static::firstOrCreate(
            ['order_id' => $order->id],
            [
                'customer_id' => static::resolveCustomerId($order),
                'invoice_date' => now(),
                'total_amount' => $order->total_amount,
                'payment_status' => $order->payment_status ?: 'paid',
            ]
        );

        if ($invoice->wasRecentlyCreated) {
            foreach ($order->items as $item) {
                $invoice->products()->attach($item->product_id, [
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                ]);
            }
        }

        return $invoice;
    }

    protected static function resolveCustomerId(Order $order): int
    {
        if ($order->user_id) {
            return $order->user->getOrCreateCustomer()->id;
        }

        if ($order->customer_id) {
            return $order->customer_id;
        }

        // Guest order: a minimal customer from the order email (profile fields nullable).
        return Customer::create([
            'first_name' => 'Guest',
            'last_name' => '',
            'email' => $order->customer_email,
        ])->id;
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class)->withPivot('quantity', 'price');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
