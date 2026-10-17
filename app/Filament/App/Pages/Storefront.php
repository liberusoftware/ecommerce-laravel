<?php

namespace App\Filament\App\Pages;

use App\Models\Store;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/** Where the current team's stores can be reached. Read-only: the platform maps domains. */
class Storefront extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected string $view = 'filament.app.pages.storefront';

    protected static ?int $navigationSort = 11;

    /** @return Collection<int, Store> */
    public function getStores(): Collection
    {
        return Store::where('team_id', Filament::getTenant()?->getKey())
            ->with(['channels.domains' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('host')])
            ->orderBy('name')
            ->get();
    }

    public function getScheme(): string
    {
        return parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
    }
}
