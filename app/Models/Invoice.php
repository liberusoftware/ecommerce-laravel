<?php

namespace App\Models;

use App\Support\Money;
use App\Traits\IsStoreScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        $invoice = static::firstOrNew(['order_id' => $order->id]);

        if (! $invoice->exists) {
            // Team and store come from the order: a webhook that marks it paid
            // has no storefront host or panel user to derive them from.
            $invoice->forceFill([
                'customer_id' => static::resolveCustomerId($order),
                'invoice_date' => now(),
                'total_amount' => $order->total_amount,
                'payment_status' => $order->payment_status ?: 'paid',
                'team_id' => $order->team_id,
                'store_id' => $order->store_id,
            ])->save();
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
        $guest = (new Customer)->forceFill([
            'first_name' => 'Guest',
            'last_name' => '',
            'email' => $order->customer_email,
            'team_id' => $order->team_id,
            'store_id' => $order->store_id,
        ]);
        $guest->save();

        return $guest->id;
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * What was invoiced: the order's own lines, which are a frozen snapshot of
     * name, choices and price, never the live catalogue.
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id', 'order_id')->orderBy('id');
    }

    public function currency(): string
    {
        return $this->order?->currency_code ?? Money::currency();
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
