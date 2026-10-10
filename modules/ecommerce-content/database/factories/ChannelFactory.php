<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => 'Web',
            'theme' => 'theme-ecommerce',
        ];
    }
}
