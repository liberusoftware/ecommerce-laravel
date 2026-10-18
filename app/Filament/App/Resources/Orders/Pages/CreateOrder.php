<?php

namespace App\Filament\App\Resources\Orders\Pages;

use App\Exceptions\CheckoutException;
use App\Filament\App\Resources\Orders\OrderResource;
use App\Filament\Schemas\StoreField;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\CheckoutService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * A manual order is built like a checkout order: prices from the products,
     * the total from the lines, stock reserved through CheckoutService, and the
     * order left pending until a transition marks it paid.
     */
    protected function handleRecordCreation(array $data): Model
    {
        return StoreField::createIn($data, fn (array $data) => $this->createOrder($data));
    }

    private function createOrder(array $data): Order
    {
        $customer = Customer::findOrFail($data['customer_id']);

        $lines = collect($data['items'])->map(function (array $item): array {
            $product = Product::findOrFail($item['product_id']);

            return ['product_id' => $product->id, 'quantity' => (int) $item['quantity'], 'price' => $product->sellingPrice()];
        })->all();

        try {
            return DB::transaction(function () use ($data, $customer, $lines): Order {
                $order = Order::create([
                    'customer_id' => $customer->id,
                    'customer_email' => $customer->email,
                    'order_date' => $data['order_date'],
                    'shipping_status' => $data['shipping_status'] ?? 'pending',
                    'payment_method' => 'manual',
                    'status' => Order::STATUS_PENDING,
                    'payment_status' => 'pending',
                    'total_amount' => round(collect($lines)->sum(fn (array $line) => $line['price'] * $line['quantity']), 2),
                ]);

                app(CheckoutService::class)->reserveStock($order, $lines);

                return $order;
            });
        } catch (CheckoutException $e) {
            throw ValidationException::withMessages(['data.items' => $e->getMessage()]);
        }
    }
}
