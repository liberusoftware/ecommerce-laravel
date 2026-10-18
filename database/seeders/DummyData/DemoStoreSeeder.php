<?php

namespace Database\Seeders\DummyData;

use App\Models\ShippingMethod;
use App\Models\Store;
use App\Services\StoreContext;
use Illuminate\Database\Seeder;

/** Contact details and shipping for the main site's store, so the demo storefront is complete. Fills blanks only. */
class DemoStoreSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::mainSite();

        if ($store === null) {
            return;
        }

        foreach ([
            'tagline' => 'Considered goods for home, desk and the outdoors.',
            'email' => 'hello@example.com',
            'phone' => '+44 20 7946 0000',
            'address_line1' => '1 Example Street',
            'city' => 'London',
            'postal_code' => 'EC1A 1AA',
            'country_code' => 'GB',
        ] as $column => $value) {
            if (blank($store->{$column})) {
                $store->{$column} = $value;
            }
        }
        $store->save();

        StoreContext::writingTo($store->id, function () {
            ShippingMethod::firstOrCreate(['name' => 'Standard delivery'], [
                'description' => 'Tracked, signature on delivery.',
                'base_rate' => 4.95,
                'weight_rate' => 0.5,
                'estimated_delivery_time' => '3–5 working days',
                'is_active' => true,
            ]);
            ShippingMethod::firstOrCreate(['name' => 'Next-day delivery'], [
                'base_rate' => 9.95,
                'weight_rate' => 0.5,
                'estimated_delivery_time' => 'Next working day',
                'is_active' => true,
            ]);
        });
    }
}
