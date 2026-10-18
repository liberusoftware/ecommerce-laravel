<?php

namespace App\Filament\Admin\Pages;

use App\Factories\PaymentGatewayFactory;
use App\Models\Store;
use App\Settings\GeneralSettings;
use App\Support\Installation;
use App\Support\Regional;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/**
 * Platform-wide settings. A merchant's own identity, currency and checkout
 * options are per store, under Stores or the merchant's Store settings page.
 */
class ManageGeneralSettings extends SettingsPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = GeneralSettings::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Platform settings';

    protected static ?string $navigationLabel = 'Platform settings';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasRole('super_admin');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + ['main_store_id' => Store::mainSite()?->getKey()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $mainStoreId = $data['main_store_id'] ?? null;
        unset($data['main_store_id']);

        if ($mainStoreId && $mainStoreId !== Store::mainSite()?->getKey()) {
            Store::query()->findOrFail($mainStoreId)->makeMainSite();
        }

        return $data;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Platform')
                    ->columns(2)
                    ->schema([
                        TextInput::make('site_name')->label('Platform name')->required()->maxLength(255),
                        TextInput::make('site_email')->label('Platform contact email')->email()->required()->maxLength(255)
                            ->helperText('Receives storefront contact messages for stores that have not set their own email.'),
                    ]),

                Section::make('Stores')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('installation_mode')
                            ->label('Installation mode')
                            ->content(fn () => ucfirst(Installation::mode()).' (deployment configuration: ECOMMERCE_MODE)'),
                        Toggle::make('allow_multiple_stores_per_team')
                            ->label('Allow a merchant to run several stores')
                            ->helperText(fn () => Installation::mode() === Installation::SINGLE
                                ? 'On a single-merchant installation, the one merchant may add further stores.'
                                : 'Otherwise each team has exactly one store.'),
                        Select::make('main_store_id')
                            ->label('Store on the main site')
                            ->options(fn () => Store::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->helperText(fn () => 'The store served at '.Store::mainSiteHost().'. Changing it moves that hostname to the chosen store.'),
                        Select::make('default_currency')
                            ->label('Currency for new stores')
                            ->options(fn () => Regional::currencies())
                            ->rule(fn () => Rule::in(array_keys(Regional::currencies())))
                            ->required()
                            ->searchable(),
                        Select::make('default_locale')
                            ->label('Language for new stores')
                            ->options(fn () => Regional::locales())
                            ->rule(fn () => Rule::in(array_keys(Regional::locales())))
                            ->required()
                            ->searchable(),
                    ]),

                Section::make('Payment gateways')
                    ->description('Credentials are deployment secrets, set in the environment. Each store chooses which configured gateways it accepts.')
                    ->schema([
                        Placeholder::make('gateway_status')
                            ->hiddenLabel()
                            ->content(fn () => new HtmlString(collect(PaymentGatewayFactory::GATEWAYS)
                                ->map(fn (string $label, string $key) => e($label).': '.(PaymentGatewayFactory::isConfigured($key) ? 'configured' : 'not configured'))
                                ->implode('<br>'))),
                    ]),
            ]);
    }
}
