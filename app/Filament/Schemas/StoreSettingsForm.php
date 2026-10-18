<?php

namespace App\Filament\Schemas;

use App\Factories\PaymentGatewayFactory;
use App\Models\Store;
use App\Support\Regional;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Illuminate\Validation\Rule;

/** The merchant-controlled settings of one Store, shared by the merchant page and the platform resource. */
final class StoreSettingsForm
{
    /** @return list<Section> */
    public static function sections(): array
    {
        return [
            Section::make('Store identity')
                ->description('How the storefront names and presents this store.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('tagline')->maxLength(255)
                        ->helperText('Shown in the footer and used as the default page description.'),
                    // Raster only: an SVG served from the public disk can carry script.
                    FileUpload::make('logo_path')
                        ->label('Logo')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                        ->maxSize(2048)
                        ->disk('public')
                        ->directory('stores')
                        ->visibility('public')
                        ->columnSpanFull(),
                    TextInput::make('email')->label('Contact email')->email()->maxLength(255)
                        ->helperText('Shown on the storefront and where contact-form messages go.'),
                    TextInput::make('phone')->label('Contact phone')->tel()->maxLength(50),
                ]),

            Section::make('Business address')
                ->description('The country also sets where tax-inclusive prices are calculated from.')
                ->columns(2)
                ->schema([
                    TextInput::make('address_line1')->label('Address line 1')->maxLength(255),
                    TextInput::make('address_line2')->label('Address line 2')->maxLength(255),
                    TextInput::make('city')->maxLength(100),
                    TextInput::make('region')->label('State / county / region')->maxLength(100),
                    TextInput::make('postal_code')->maxLength(20),
                    Select::make('country_code')
                        ->label('Country')
                        ->options(fn () => Regional::countries())
                        ->rule(fn () => Rule::in(array_keys(Regional::countries())))
                        ->searchable(),
                ]),

            Section::make('Currency and region')
                ->columns(2)
                ->schema([
                    Select::make('currency_code')
                        ->label('Currency')
                        ->options(fn () => Regional::currencies())
                        ->rule(fn () => Rule::in(array_keys(Regional::currencies())))
                        ->required()
                        ->searchable()
                        ->helperText('Prices, carts and new orders use this currency. Existing orders keep theirs.'),
                    Select::make('locale')
                        ->label('Language and formatting')
                        ->options(fn () => Regional::locales())
                        ->rule(fn () => Rule::in(array_keys(Regional::locales())))
                        ->required()
                        ->searchable(),
                    Toggle::make('display_prices_with_tax')
                        ->label('Show prices including tax')
                        ->helperText('Catalogue prices include tax at the store country\'s rate. Checkout always itemises tax.')
                        ->columnSpanFull(),
                ]),

            Section::make('Payments')
                ->schema([
                    CheckboxList::make('payment_gateways')
                        ->label('Accepted at checkout')
                        ->options(PaymentGatewayFactory::GATEWAYS)
                        ->descriptions(collect(PaymentGatewayFactory::GATEWAYS)
                            ->map(fn ($label, string $key) => PaymentGatewayFactory::isConfigured($key)
                                ? 'Credentials configured on this deployment.'
                                : 'No credentials on this deployment yet — ask the platform operator.')
                            ->all()),
                ]),

            Section::make('Storefront footer')
                ->columns(2)
                ->schema([
                    TextInput::make('footer_text')
                        ->label('Footer text')
                        ->placeholder('© '.date('Y').' Your store')
                        ->maxLength(500)
                        ->columnSpanFull(),
                    ...collect(Store::SOCIAL_NETWORKS)
                        ->map(fn (string $label, string $key) => TextInput::make("social_links.{$key}")
                            ->label($label.' URL')
                            ->url()
                            ->maxLength(255))
                        ->values()
                        ->all(),
                ]),
        ];
    }

    /** Form state for a store: an unset gateway list means every gateway is accepted. */
    public static function fill(Store $store): array
    {
        return array_merge($store->attributesToArray(), [
            'payment_gateways' => $store->paymentGateways(),
            'social_links' => $store->social_links ?? [],
        ]);
    }

    /** Empty social links dropped, the gateway list made a plain list. */
    public static function dehydrate(array $data): array
    {
        if (array_key_exists('social_links', $data)) {
            $data['social_links'] = array_filter((array) $data['social_links'], 'filled') ?: null;
        }

        if (array_key_exists('payment_gateways', $data)) {
            $data['payment_gateways'] = array_values((array) $data['payment_gateways']);
        }

        return $data;
    }
}
