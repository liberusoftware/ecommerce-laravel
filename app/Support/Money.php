<?php

namespace App\Support;

use App\Models\Store;
use App\Settings\GeneralSettings;
use Illuminate\Support\Number;
use Throwable;

/**
 * Formats an amount in the storefront's currency and locale, or a given currency
 * for records that carry their own (an order keeps the currency it was placed in).
 */
final class Money
{
    public static function format(float|int|string|null $amount, ?string $currency = null): string
    {
        $store = Store::current();

        return Number::currency(
            (float) $amount,
            in: $currency ?: self::currency(),
            locale: $store?->locale ?: app()->getLocale(),
        );
    }

    public static function currency(): string
    {
        if ($code = Store::current()?->currency_code) {
            return $code;
        }

        try {
            return app(GeneralSettings::class)->default_currency;
        } catch (Throwable) {
            return strtoupper((string) config('ecommerce.default_currency', 'USD'));
        }
    }
}
