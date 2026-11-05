<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DropshippingService
{
    protected $suppliers = [];

    public function __construct()
    {
        $this->suppliers = config('dropshipping.suppliers', []);
    }

    /**
     * Get all registered suppliers
     *
     * @return array
     */
    public function getSuppliers()
    {
        return $this->suppliers;
    }

    /**
     * Check product availability with a specific supplier
     *
     * @param  string  $supplierId
     * @param  string  $productSku
     * @param  int  $quantity
     * @return array
     */
    public function checkAvailability($supplierId, $productSku, $quantity = 1)
    {
        $supplier = $this->getSupplier($supplierId);

        if (! $supplier) {
            return ['success' => false, 'message' => 'Supplier not found'];
        }

        try {
            if ($supplierId === 'printful') {
                // Printful has no stock endpoint; a sync variant's state, by its external id (the SKU), is the nearest.
                $response = Http::withHeaders($this->getHeaders($supplier))
                    ->get($supplier['endpoints']['availability'].'/@'.rawurlencode($productSku));

                return $response->successful()
                    ? ['success' => true, 'data' => $response->json('result')]
                    : ['success' => false, 'message' => 'Failed to check availability', 'error' => $response->body()];
            }

            $response = Http::withHeaders($this->getHeaders($supplier))
                ->get($supplier['endpoints']['availability'], [
                    'sku' => $productSku,
                    'quantity' => $quantity,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            return ['success' => false, 'message' => 'Failed to check availability', 'error' => $response->body()];
        } catch (Exception $e) {
            Log::error('Dropshipping availability check failed: '.$e->getMessage());

            return ['success' => false, 'message' => 'Error checking availability', 'error' => $e->getMessage()];
        }
    }

    /**
     * Place an order with a supplier
     *
     * @param  string  $supplierId
     * @return array
     */
    public function placeOrder($supplierId, array $orderData)
    {
        $supplier = $this->getSupplier($supplierId);

        if (! $supplier) {
            return ['success' => false, 'message' => 'Supplier not found'];
        }

        try {
            // Transform payload for specific suppliers (DropXL requires specific shape)
            $payload = $this->transformOrderPayloadForSupplier($supplierId, $orderData);

            // Printful takes these as query parameters; external_id plus update_existing makes a retry safe.
            $endpoint = $supplier['endpoints']['orders'].($supplierId === 'printful' ? '?confirm=1&update_existing=1' : '');

            $response = Http::withHeaders($this->getHeaders($supplier))
                ->post($endpoint, $payload);

            if ($response->successful()) {
                return ['success' => true, 'data' => $supplierId === 'printful' ? $response->json('result') : $response->json()];
            }

            return ['success' => false, 'message' => 'Failed to place order', 'error' => $response->body()];
        } catch (Exception $e) {
            Log::error('Dropshipping order placement failed: '.$e->getMessage());

            return ['success' => false, 'message' => 'Error placing order', 'error' => $e->getMessage()];
        }
    }

    /**
     * Track an order with a supplier
     *
     * @param  string  $supplierId
     * @param  string  $orderReference
     * @return array
     */
    public function trackOrder($supplierId, $orderReference)
    {
        $supplier = $this->getSupplier($supplierId);

        if (! $supplier) {
            return ['success' => false, 'message' => 'Supplier not found'];
        }

        try {
            $response = $supplierId === 'printful'
                ? Http::withHeaders($this->getHeaders($supplier))->get($supplier['endpoints']['tracking'].'/'.rawurlencode($orderReference))
                : Http::withHeaders($this->getHeaders($supplier))->get($supplier['endpoints']['tracking'], ['reference' => $orderReference]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $supplierId === 'printful' ? $response->json('result') : $response->json()];
            }

            return ['success' => false, 'message' => 'Failed to track order', 'error' => $response->body()];
        } catch (Exception $e) {
            Log::error('Dropshipping order tracking failed: '.$e->getMessage());

            return ['success' => false, 'message' => 'Error tracking order', 'error' => $e->getMessage()];
        }
    }

    /**
     * Get a specific supplier by ID
     *
     * @param  string  $supplierId
     * @return array|null
     */
    protected function getSupplier($supplierId)
    {
        return $this->suppliers[$supplierId] ?? null;
    }

    /**
     * Get headers for API requests
     *
     * @param  array  $supplier
     * @return array
     */
    protected function getHeaders($supplier)
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if (isset($supplier['auth']['type']) && $supplier['auth']['type'] === 'api_key') {
            $headers[$supplier['auth']['header']] = $supplier['auth']['key'];
        }

        if (! empty($supplier['store_id'])) {
            $headers['X-PF-Store-Id'] = (string) $supplier['store_id'];
        }

        return $headers;
    }

    /**
     * Transform order payload for supplier-specific APIs
     *
     * @param  string  $supplierId
     * @return array
     */
    protected function transformOrderPayloadForSupplier($supplierId, array $orderData)
    {
        if ($supplierId === 'dropxl') {
            // DropXL expects a top-level "customer" object and "items" array with sku & qty
            $payload = [
                'customer' => [
                    'name' => $orderData['recipient_name'] ?? ($orderData['customer_name'] ?? null),
                    'email' => $orderData['recipient_email'] ?? ($orderData['customer_email'] ?? null),
                    'address' => $orderData['shipping_address'] ?? null,
                ],
                'shipping' => [
                    'method' => $orderData['shipping_method'] ?? null,
                    'cost' => $orderData['shipping_cost'] ?? 0,
                ],
                'items' => [],
                'reference' => $orderData['reference'] ?? null,
            ];

            foreach ($orderData['items'] as $item) {
                $payload['items'][] = [
                    'sku' => $item['sku'] ?? $item['product_sku'] ?? $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ];
            }

            return $payload;
        }

        if ($supplierId === 'printful') {
            return $this->printfulOrder($orderData);
        }

        // Default: pass through
        return $orderData;
    }

    /** Printful's order shape. A variant mapped to a Printful sync variant goes by that id, otherwise by SKU. */
    private function printfulOrder(array $orderData): array
    {
        $address = $orderData['shipping_address'] ?? null;
        $address = is_array($address) ? $address : ['address1' => $address];

        $recipient = array_filter([
            'name' => $orderData['recipient_name'] ?? $orderData['customer_name'] ?? null,
            'email' => $orderData['recipient_email'] ?? $orderData['customer_email'] ?? null,
            'address1' => $address['address1'] ?? null,
            'address2' => $address['address2'] ?? null,
            'city' => $orderData['shipping_city'] ?? null,
            'state_code' => $orderData['shipping_state'] ?? null,
            'country_code' => $orderData['shipping_country'] ?? null,
            'zip' => $orderData['shipping_postal_code'] ?? null,
        ], fn ($value) => filled($value));

        $items = array_map(fn (array $item) => array_filter([
            'sync_variant_id' => filled($item['supplier_variant_id'] ?? null) ? (int) $item['supplier_variant_id'] : null,
            'external_variant_id' => blank($item['supplier_variant_id'] ?? null) ? ($item['sku'] ?? null) : null,
            'quantity' => (int) $item['quantity'],
            'retail_price' => number_format((float) $item['price'], 2, '.', ''),
        ], fn ($value) => filled($value)), $orderData['items'] ?? []);

        return array_filter([
            'external_id' => $orderData['reference'] ?? null,
            'shipping' => 'STANDARD',
            'recipient' => $recipient,
            'items' => $items,
            'gift' => filled($orderData['gift_message'] ?? null) ? ['message' => $orderData['gift_message']] : null,
        ], fn ($value) => $value !== null);
    }
}
