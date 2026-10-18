<?php

namespace App\Services;

use App\Models\Store;
use App\Support\EuVat;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * EU VAT-number validation (VIES) and the intra-EU B2B reverse-charge rule.
 *
 * When an EU-established store sells to a business in ANOTHER member state that supplies
 * a valid VAT number, the supply is zero-rated and the buyer accounts for the VAT
 * (reverse charge). Validation fails CLOSED: if VIES can't confirm the number, no
 * reverse charge is applied and normal VAT is charged — we never zero-rate on an
 * unverified number.
 *
 * @see https://ec.europa.eu/taxation_customs/vies/
 */
class ViesService
{
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms';

    /** Whether the reverse charge applies to an order billed with this VAT number. */
    public function reverseChargeApplies(?string $vatNumber): bool
    {
        $vatNumber = $this->normalise($vatNumber);
        if ($vatNumber === null) {
            return false;
        }

        $storeCountry = strtoupper((string) (Store::current()?->country_code ?: config('ecommerce.store_country')));
        if (! EuVat::isMemberState($storeCountry)) {
            return false;   // the store isn't in the EU — reverse charge doesn't apply
        }

        $country = substr($vatNumber, 0, 2);
        $number = substr($vatNumber, 2);

        if (! EuVat::isMemberState($country) || $country === $storeCountry) {
            return false;   // non-EU number, or a domestic sale (normal VAT)
        }

        return $this->isValid($country, $number);
    }

    /** Validate a VAT number with VIES. Returns false on any error (fail closed). */
    public function isValid(string $country, string $number): bool
    {
        try {
            $response = Http::timeout((int) config('ecommerce.vies_timeout', 10))
                ->acceptJson()
                ->get(self::ENDPOINT."/{$country}/vat/{$number}");

            return $response->successful() && $response->json('isValid') === true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Strip spaces/punctuation and upper-case; null if too short to hold a country + number. */
    public function normalise(?string $vatNumber): ?string
    {
        if ($vatNumber === null) {
            return null;
        }

        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vatNumber));

        return strlen($value) >= 3 ? $value : null;
    }
}
