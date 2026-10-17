<?php

namespace Database\Seeders\DummyData;

/**
 * The demo store's catalogue, shared with tools/generate-demo-product-images.py.
 */
final class DemoCatalogue
{
    public const IMAGE_DIRECTORY = 'products/demo';

    public static function load(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/catalogue.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function imageSource(string $slug): string
    {
        return __DIR__."/images/{$slug}.webp";
    }
}
