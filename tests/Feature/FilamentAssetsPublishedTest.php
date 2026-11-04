<?php

namespace Tests\Feature;

use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;
use Tests\TestCase;

/** The panels serve Filament's assets from public/; a copy from an older Filament leaves Alpine components undefined. */
class FilamentAssetsPublishedTest extends TestCase
{
    public function test_every_published_panel_asset_matches_the_installed_package(): void
    {
        $assets = [...FilamentAsset::getScripts(), ...FilamentAsset::getStyles(), ...FilamentAsset::getAlpineComponents()];
        $stale = collect($assets)
            ->reject(fn (Asset $asset) => $asset->isRemote() || blank($asset->getPath()) || ! is_file($asset->getPath()))
            ->filter(fn (Asset $asset) => ! is_file($asset->getPublicPath()) || md5_file($asset->getPublicPath()) !== md5_file($asset->getPath()))
            ->map(fn (Asset $asset) => str_replace(public_path().'/', '', $asset->getPublicPath()))
            ->values()->all();

        $this->assertNotEmpty($assets);
        $this->assertSame([], $stale, 'Published Filament assets differ from the installed package. Run php artisan filament:assets.');
    }
}
