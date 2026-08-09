<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TaxRate;

class TaxCalculator
{
    /**
     * Calculate tax for cart items.
     *
     * `$discount` is a cart-level discount — a coupon — and tax lands on what is
     * left after it. That rule came from `TaxService`, which held it as a flat
     * subtraction from one blended subtotal; correct only while every line
     * shares a rate. Here it is **pro-rata**: each line's taxable base shrinks
     * by the same proportion, so a cart mixing a standard-rated and a
     * reduced-rated item is taxed on the right amount of each.
     *
     * Both checkouts used to compute that proportion themselves, identically,
     * next to the call. A tax rule living at two call sites is a rule that will
     * eventually live at one.
     *
     * Pass **every** cart line, including ones with no `product`: they are not
     * taxed (nothing says at what rate), but they are part of the cart the
     * discount was given against, so they belong in its denominator.
     *
     * Shipping tax is not discounted — a cart coupon reduces what was bought,
     * not what it cost to send.
     */
    public function calculateCartTax(array $items, array $shippingAddress, float $shippingCost = 0, float $discount = 0): array
    {
        $taxLines = [];
        $totalTax = 0;

        $country = $shippingAddress['country'] ?? null;
        $state = $shippingAddress['state'] ?? null;
        $city = $shippingAddress['city'] ?? null;
        $zipCode = $shippingAddress['postal_code'] ?? null;

        if (! $country) {
            return ['total' => 0, 'lines' => []];
        }

        $discountFactor = $this->discountFactor($items, $discount);

        // Calculate tax for each item
        foreach ($items as $item) {
            $product = $item['product'] ?? null;
            $quantity = $item['quantity'] ?? 1;
            $price = $item['price'] ?? 0;

            if (! $product || ! ($product instanceof Product)) {
                continue;
            }

            // Skip if product is not taxable
            if (! ($product->tax_status ?? true)) {
                continue;
            }

            $taxClassId = $product->tax_class_id;
            $itemSubtotal = $price * $quantity * $discountFactor;

            // Find matching tax rates
            $rates = TaxRate::findMatchingRates($country, $state, $city, $zipCode, $taxClassId);

            $this->applyRates($rates, $itemSubtotal, $totalTax, $taxLines);
        }

        // Calculate shipping tax if applicable
        if ($shippingCost > 0) {
            $shippingRates = TaxRate::findMatchingRates($country, $state, $city, $zipCode)
                ->where('shipping', true);

            $this->applyRates($shippingRates, $shippingCost, $totalTax, $taxLines, ' (Shipping)');
        }

        return [
            'total' => round($totalTax, 2),
            'lines' => array_values($taxLines),
        ];
    }

    /**
     * The proportion of each line that remains taxable after a cart discount.
     *
     * 1.0 when there is no discount, and 0.0 when the discount covers the cart —
     * a coupon can zero the tax but never make it negative.
     *
     * @param  array<array{product?: mixed, price?: float, quantity?: int}>  $items
     */
    private function discountFactor(array $items, float $discount): float
    {
        if ($discount <= 0) {
            return 1.0;
        }

        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
        }

        if ($subtotal <= 0) {
            return 1.0;
        }

        return max(0, $subtotal - $discount) / $subtotal;
    }

    /**
     * Apply a set of tax rates to a base amount, honouring compound (tax-on-tax)
     * rates: simple rates are computed on the base, then compound rates on the base
     * plus the simple tax. Accumulates into $totalTax and the grouped $taxLines.
     *
     * @param  iterable<TaxRate>  $rates
     */
    private function applyRates(iterable $rates, float $base, float &$totalTax, array &$taxLines, string $labelSuffix = ''): void
    {
        $record = function (TaxRate $rate, float $on) use (&$totalTax, &$taxLines, $labelSuffix): float {
            $amount = $rate->calculateTax($on);
            $totalTax += $amount;

            $key = $rate->id;
            if (! isset($taxLines[$key])) {
                $taxLines[$key] = [
                    'label' => $rate->name.$labelSuffix,
                    'rate' => $rate->rate,
                    'amount' => 0,
                    'compound' => $rate->compound,
                ];
            }
            $taxLines[$key]['amount'] += $amount;

            return $amount;
        };

        $simpleTax = 0.0;
        foreach ($rates as $rate) {
            if (! $rate->compound) {
                $simpleTax += $record($rate, $base);
            }
        }
        foreach ($rates as $rate) {
            if ($rate->compound) {
                $record($rate, $base + $simpleTax);
            }
        }
    }

    /**
     * Calculate tax for a single product
     */
    public function calculateProductTax(Product $product, float $price, array $location): float
    {
        if (! ($product->tax_status ?? true)) {
            return 0;
        }

        $rates = TaxRate::findMatchingRates(
            $location['country'],
            $location['state'] ?? null,
            $location['city'] ?? null,
            $location['postal_code'] ?? null,
            $product->tax_class_id
        );

        $totalTax = 0;
        foreach ($rates as $rate) {
            $totalTax += $rate->calculateTax($price);
        }

        return round($totalTax, 2);
    }

    /**
     * Get tax-inclusive price
     */
    public function getPriceWithTax(float $price, Product $product, array $location): float
    {
        $tax = $this->calculateProductTax($product, $price, $location);

        return round($price + $tax, 2);
    }

    /**
     * Check if prices should be displayed with tax
     */
    public function shouldDisplayPricesWithTax(): bool
    {
        return config('ecommerce.display_prices_with_tax', false);
    }

    /**
     * The price to show on catalogue/cart pages: bare when the store displays
     * tax-exclusive prices, otherwise tax-inclusive using the store's own
     * location (the visitor's address isn't known before checkout).
     */
    public function displayPrice(Product $product): float
    {
        $price = (float) $product->price;

        if (! $this->shouldDisplayPricesWithTax()) {
            return $price;
        }

        return $this->getPriceWithTax($price, $product, $this->storeLocation());
    }

    private function storeLocation(): array
    {
        return [
            'country' => config('ecommerce.store_country', 'US'),
            'state' => config('ecommerce.store_state'),
        ];
    }
}
