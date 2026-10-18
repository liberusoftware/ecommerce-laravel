<?php

namespace App\Support;

use Locale;
use ResourceBundle;

/** Option lists for regional settings, from the ICU data the intl extension ships. */
final class Regional
{
    /** Region codes ICU lists that are not countries. */
    private const NOT_COUNTRIES = ['EU', 'EZ', 'QO', 'UN', 'ZZ'];

    /** @return array<string, string> ISO 3166-1 alpha-2 => name */
    public static function countries(): array
    {
        $countries = [];

        foreach ((new ResourceBundle('en', 'ICUDATA-region'))->get('Countries') as $code => $name) {
            if (preg_match('/^[A-Z]{2}$/', (string) $code) && ! in_array($code, self::NOT_COUNTRIES, true)) {
                $countries[$code] = $name;
            }
        }

        asort($countries);

        return $countries;
    }

    /** @return array<string, string> ISO 4217 => "GBP — British Pound" */
    public static function currencies(): array
    {
        $currencies = [];

        foreach ((new ResourceBundle('en', 'ICUDATA-curr'))->get('Currencies') as $code => $data) {
            if (preg_match('/^[A-Z]{3}$/', (string) $code)) {
                $currencies[$code] = $code.' — '.$data->get(1);
            }
        }

        ksort($currencies);

        return $currencies;
    }

    /** @return array<string, string> locale => "English (United Kingdom)" */
    public static function locales(): array
    {
        $locales = [];

        foreach (ResourceBundle::getLocales('') as $locale) {
            if (preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale)) {
                $locales[$locale] = Locale::getDisplayName($locale, 'en');
            }
        }

        asort($locales);

        return $locales;
    }
}
