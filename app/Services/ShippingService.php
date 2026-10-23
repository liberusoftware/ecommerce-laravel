<?php

namespace App\Services;

use App\Factories\CarrierRateFactory;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingQuote;
use App\Services\Shipping\CarrierRate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class ShippingService
{
    /**
     * Fetch live rates and PERSIST each as a ShippingQuote scoped to this session, so
     * the buyer can later select one by id and the server bills its stored amount.
     * Returns the persisted quotes (empty when no carrier is configured/reachable).
     *
     * @return Collection<int, ShippingQuote>
     */
    public function quoteLiveRates($cart, array $to, string $sessionId, ?int $userId = null): Collection
    {
        $rates = $this->getLiveRates($cart, $to);
        $expiresAt = now()->addMinutes((int) config('shipping.quote_ttl', 30));

        return collect($rates)->map(fn (CarrierRate $rate) => ShippingQuote::create([
            'session_id' => $sessionId,
            'user_id' => $userId,
            'carrier' => $rate->carrier,
            'service' => $rate->service,
            'amount' => $rate->amount,
            'currency' => $rate->currency,
            'delivery_days' => $rate->deliveryDays,
            'rate_id' => $rate->rateId,
            'expires_at' => $expiresAt,
        ]));
    }

    /**
     * Resolve a quote the caller is allowed to use: it must belong to this session (or,
     * for a logged-in buyer, to them) and not be expired. Returns null otherwise — the
     * caller rejects checkout rather than trusting a foreign, stale, or client-fabricated
     * rate.
     */
    public function resolveQuote(int $quoteId, string $sessionId, ?int $userId = null): ?ShippingQuote
    {
        return ShippingQuote::query()
            ->where('id', $quoteId)
            ->where(function ($query) use ($sessionId, $userId) {
                $query->where('session_id', $sessionId);
                if ($userId !== null) {
                    $query->orWhere('user_id', $userId);
                }
            })
            ->active()
            ->first();
    }

    /**
     * Live carrier rates for a cart shipping to $to. Returns [] when no live-rate
     * carrier is configured or the carrier is unreachable — the caller then falls
     * back to the flat DB methods from getAvailableShippingMethods().
     *
     * @param  array  $to  destination address (name/street1/city/state/zip/country)
     * @param  array|null  $from  origin; defaults to config('shipping.origin')
     * @return CarrierRate[]
     */
    public function getLiveRates($cart, array $to, ?array $from = null): array
    {
        $carrier = CarrierRateFactory::create();
        if ($carrier === null) {
            return [];
        }

        $parcel = ['weight' => $this->calculateTotalWeight($cart)];

        return $carrier->getRates($parcel, $from ?? (array) config('shipping.origin'), $to);
    }

    public function getAvailableShippingMethods($cart = null, $address = null)
    {
        // Only offer active methods; a deactivated method must never be selectable.
        $availableMethods = ShippingMethod::where('is_active', true)->get();

        if (! $cart || ! $address) {
            return $availableMethods;
        }

        // Filter methods based on package weight, dimensions, and destination
        return $availableMethods->filter(function ($method) use ($cart, $address) {
            return $this->isMethodAvailable($method, $cart, $address);
        });
    }

    public function calculateShippingCost(ShippingMethod $method, $cart, $address)
    {
        // Implement logic to calculate shipping cost based on the method, cart contents, and address
        $baseRate = $method->base_rate;
        $weightRate = $this->calculateWeightRate($method, $cart);
        $distanceRate = $this->calculateDistanceRate($method, $address);

        // Round to cents: float weight math otherwise leaks e.g. 0.30000000000000004.
        return round($baseRate + $weightRate + $distanceRate, 2);
    }

    public function verifyAddress($address)
    {
        // Implement address verification logic
        // This is a placeholder implementation. In a real-world scenario, you would integrate with an address verification API.
        $response = Http::get('https://api.address-verifier.com', [
            'address' => $address,
            'api_key' => config('services.address_verifier.api_key'),
        ]);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    private function isMethodAvailable($method, $cart, $address)
    {
        // Implement logic to check if the shipping method is available for the given cart and address
        // This is a simplified example. You would need to implement more complex logic based on your specific requirements.
        $totalWeight = $this->calculateTotalWeight($cart);
        $maxWeight = $method->max_weight;

        // Null max_weight = no weight limit. Without this guard, `$w <= null`
        // evaluates as `$w <= 0` in PHP, wrongly rejecting unlimited methods.
        return $maxWeight === null || $totalWeight <= $maxWeight;
    }

    private function calculateWeightRate($method, $cart)
    {
        $totalWeight = $this->calculateTotalWeight($cart);

        return $totalWeight * $method->weight_rate;
    }

    private function calculateDistanceRate($method, $address)
    {
        // Implement logic to calculate distance-based rate
        // This is a placeholder. You would need to integrate with a distance calculation service.
        return 0;
    }

    private function calculateTotalWeight($cart)
    {
        // The storefront cart carries each line's weight. A cart without it must be
        // keyed by product id, and the weights are looked up in one query.
        $lookupIds = [];
        foreach ($cart as $productId => $item) {
            if (! isset($item['weight'])) {
                $lookupIds[] = $productId;
            }
        }
        $weights = empty($lookupIds)
            ? collect()
            : Product::whereIn('id', $lookupIds)->pluck('weight', 'id');

        $total = 0.0;
        foreach ($cart as $productId => $item) {
            $weight = (float) ($item['weight'] ?? $weights[$productId] ?? 0);
            $total += $weight * $item['quantity'];
        }

        return $total;
    }

    public function calculateDropShippingCost(ShippingMethod $method, $cart, $address)
    {
        // Add a small premium for drop shipping
        $standardCost = $this->calculateShippingCost($method, $cart, $address);
        $dropShippingPremium = config('shipping.drop_shipping_premium', 2.00);

        return $standardCost + $dropShippingPremium;
    }
}
