<?php

namespace App\Filament\Schemas;

use App\Models\FacebookConnection;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOptionGroup;
use App\Models\TaxClass;
use App\Services\StoreContext;
use App\Support\Money;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** The product form both panels use, so the two cannot drift. Every field here is read by the storefront, cart or checkout. */
final class ProductForm
{
    /** Raster only: an SVG served same-origin from the public disk can carry script. */
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Grid::make(1)
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        self::details(),
                        self::media(),
                        self::pricing(),
                        self::inventory(),
                        self::downloads(),
                        self::seo(),
                    ]),
                Grid::make(1)
                    ->columnSpan(['lg' => 1])
                    ->schema([self::publishing(), self::organisation()]),
            ]);
    }

    private static function details(): Section
    {
        return Section::make('Product')
            ->columns(2)
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    // Follows the name until the merchant edits the slug, and never after the product exists.
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $old, ?string $state, string $operation): void {
                        if ($operation !== 'create') {
                            return;
                        }
                        $slug = (string) ($get('slug') ?? '');
                        if ($slug === '' || $slug === Str::slug((string) $old)) {
                            $set('slug', Str::slug((string) $state));
                        }
                    })
                    ->columnSpanFull(),
                TextInput::make('slug')
                    ->label('URL slug')
                    ->required()
                    ->alphaDash()
                    ->maxLength(255)
                    ->rule(fn (?Product $record, Get $get) => self::uniqueInStore('slug', $record, $get))
                    ->helperText('Changing it changes the product\'s web address.'),
                TextInput::make('sku')
                    ->label('SKU')
                    ->maxLength(100)
                    ->rule(fn (?Product $record, Get $get) => self::uniqueInStore('sku', $record, $get)),
                TextInput::make('short_description')
                    ->label('Summary')
                    ->helperText('One line, shown on product cards and in search results.')
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('description')
                    ->rows(6)
                    ->maxLength(65535)
                    ->columnSpanFull(),
            ]);
    }

    private static function media(): Section
    {
        return Section::make('Images')
            ->collapsible()
            ->schema([
                FileUpload::make('featured_image')
                    ->label('Main image')
                    ->acceptedFileTypes(self::IMAGE_TYPES)
                    ->maxSize(4096)
                    ->disk('public')
                    ->directory('products')
                    ->visibility('public'),
                Repeater::make('images')
                    ->label('Gallery')
                    ->relationship('images')
                    ->orderColumn('order')
                    ->reorderable()
                    ->grid(3)
                    ->defaultItems(0)
                    ->addActionLabel('Add image')
                    ->schema([
                        FileUpload::make('image')
                            ->hiddenLabel()
                            ->required()
                            ->acceptedFileTypes(self::IMAGE_TYPES)
                            ->maxSize(4096)
                            ->disk('public')
                            ->directory('products')
                            ->visibility('public'),
                    ]),
            ]);
    }

    private static function pricing(): Section
    {
        $currency = fn () => Money::currency();

        return Section::make('Pricing')
            ->columns(2)
            ->schema([
                Select::make('pricing_type')
                    ->options([
                        'fixed' => 'Fixed price',
                        'free' => 'Free',
                        'donation' => 'Pay what you want',
                    ])
                    ->default('fixed')
                    ->required()
                    ->selectablePlaceholder(false)
                    ->live()
                    ->columnSpanFull(),
                TextInput::make('price')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(99999999)
                    ->prefix($currency)
                    ->required(fn (Get $get) => $get('pricing_type') === 'fixed')
                    ->visible(fn (Get $get) => $get('pricing_type') === 'fixed')
                    ->live(onBlur: true),
                TextInput::make('sale_price')
                    ->numeric()
                    ->minValue(0)
                    ->prefix($currency)
                    ->lt('price')
                    ->helperText('Leave empty when not on sale. Shoppers see the price struck through.')
                    ->visible(fn (Get $get) => $get('pricing_type') === 'fixed'),
                TextInput::make('suggested_price')
                    ->numeric()
                    ->minValue(0)
                    ->prefix($currency)
                    ->visible(fn (Get $get) => $get('pricing_type') === 'donation'),
                TextInput::make('minimum_price')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->prefix($currency)
                    ->visible(fn (Get $get) => $get('pricing_type') === 'donation'),
                Toggle::make('tax_status')
                    ->label('Charge tax')
                    ->default(true),
                Select::make('tax_class_id')
                    ->label('Tax class')
                    ->options(fn () => TaxClass::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->placeholder('Standard rate'),
            ]);
    }

    private static function inventory(): Section
    {
        return Section::make('Inventory and shipping')
            ->columns(3)
            ->schema([
                Toggle::make('track_inventory')
                    ->label('Track stock')
                    ->default(fn (Get $get) => ! $get('is_downloadable'))
                    ->live()
                    ->helperText('Off sells any quantity: for made-to-order goods. Downloads start untracked.')
                    ->columnSpanFull(),
                TextInput::make('inventory_count')
                    ->label('Stock')
                    ->visible(fn (Get $get) => (bool) $get('track_inventory'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    // Changes after creation go through the stock actions, which log them.
                    ->disabledOn('edit')
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Use “Adjust stock” on the product list; adjustments are logged.' : null),
                TextInput::make('low_stock_threshold')
                    ->label('Low-stock alert at')
                    ->visible(fn (Get $get) => (bool) $get('track_inventory'))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                TextInput::make('weight')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->suffix('kg')
                    ->helperText('Used by weight-based shipping.'),
            ]);
    }

    private static function downloads(): Section
    {
        return Section::make('Digital download')
            ->columns(2)
            ->collapsible()
            ->collapsed(fn (?Product $record) => ! $record?->is_downloadable)
            ->schema([
                Toggle::make('is_downloadable')
                    ->label('This is a downloadable product')
                    ->helperText('Downloadable products need no shipping, and their stock is not tracked unless you turn tracking back on.')
                    ->live()
                    ->afterStateUpdated(fn (Set $set, ?bool $state) => $set('track_inventory', ! $state))
                    ->columnSpanFull(),
                FileUpload::make('downloadable_file')
                    ->label('File')
                    ->disk('local')
                    ->directory('downloadable_products')
                    ->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'application/zip'])
                    ->maxSize(50 * 1024)
                    ->required(fn (Get $get) => (bool) $get('is_downloadable'))
                    ->visible(fn (Get $get) => (bool) $get('is_downloadable'))
                    ->columnSpanFull(),
                TextInput::make('download_limit')
                    ->numeric()
                    ->minValue(1)
                    ->visible(fn (Get $get) => (bool) $get('is_downloadable')),
                DateTimePicker::make('expiration_time')
                    ->label('Downloads expire')
                    ->visible(fn (Get $get) => (bool) $get('is_downloadable')),
            ]);
    }

    private static function seo(): Section
    {
        return Section::make('Search engine listing')
            ->collapsible()
            ->collapsed()
            ->schema([
                TextInput::make('meta_title')->maxLength(70)->helperText('Defaults to the product name.'),
                Textarea::make('meta_description')->maxLength(160)->rows(2)->helperText('Defaults to the summary.'),
            ]);
    }

    private static function publishing(): Section
    {
        return Section::make('Publishing')
            ->schema([
                StoreField::make(),
                Select::make('status')
                    ->options(Product::STATUSES)
                    ->default(Product::STATUS_ACTIVE)
                    ->required()
                    ->selectablePlaceholder(false)
                    ->helperText('Only active products can be seen and bought.'),
                Select::make('visibility')
                    ->options(Product::VISIBILITIES)
                    ->default(Product::VISIBILITY_VISIBLE)
                    ->required()
                    ->selectablePlaceholder(false),
                Toggle::make('is_featured')
                    ->label('Feature on the home page'),
                Toggle::make('list_on_facebook')
                    ->label('List on Facebook')
                    ->helperText('Pushes this product into your Meta Catalog.')
                    ->visible(fn (?Product $record) => FacebookConnection::forTeam($record?->team_id ?? Filament::getTenant()?->getKey()) !== null),
            ]);
    }

    private static function organisation(): Section
    {
        return Section::make('Organisation')
            ->schema([
                Select::make('category_id')
                    ->label('Category')
                    // Only this product's own store's categories, offered and accepted.
                    ->relationship('category', 'name', modifyQueryUsing: fn ($query, Get $get, ?Product $record) => $query
                        ->where('product_categories.store_id', self::storeOf($record, $get))
                        ->orderBy('name'))
                    ->getOptionLabelFromRecordUsing(fn (ProductCategory $category) => $category->parent
                        ? $category->parent->name.' › '.$category->name
                        : $category->name)
                    ->rule(fn (Get $get, ?Product $record) => Rule::exists('product_categories', 'id')
                        ->where('store_id', self::storeOf($record, $get)))
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        TextInput::make('name')->required()->maxLength(255),
                    ])
                    ->createOptionUsing(function (array $data, Get $get, ?Product $record): int {
                        $create = fn () => ProductCategory::create(['name' => $data['name']]);
                        $storeId = self::storeOf($record, $get);

                        return ($storeId ? StoreContext::writingTo($storeId, $create) : $create())->getKey();
                    }),
                Select::make('tags')
                    ->multiple()
                    ->relationship('tags', 'name')
                    ->preload(),
                // Only this product's own store's groups, offered and accepted.
                Select::make('optionGroups')
                    ->label('Option groups')
                    ->multiple()
                    ->relationship('optionGroups', 'name', modifyQueryUsing: fn ($query, Get $get, ?Product $record) => $query
                        ->where('product_option_groups.store_id', self::storeOf($record, $get)))
                    ->rule(fn (Get $get, ?Product $record) => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                        $ids = array_map('intval', (array) $value);
                        $own = ProductOptionGroup::withoutGlobalScopes()->whereKey($ids)->where('store_id', self::storeOf($record, $get))->count();
                        if ($own !== count($ids)) {
                            $fail('Option groups must belong to this product\'s store.');
                        }
                    })
                    ->preload()
                    ->hidden(fn (Get $get) => $get('pricing_type') === 'donation')
                    ->helperText('Choices shoppers make when adding this product, such as size or extras.'),
            ]);
    }

    private static function storeOf(?Product $record, Get $get): ?int
    {
        $storeId = $record?->store_id ?? $get('store_id') ?? StoreContext::forWrites();

        return $storeId === null ? null : (int) $storeId;
    }

    /** Slugs and SKUs are unique within a store, matching the (store_id, column) indexes. */
    private static function uniqueInStore(string $column, ?Product $record, Get $get): Closure
    {
        return function (string $attribute, $value, Closure $fail) use ($column, $record, $get): void {
            if (blank($value)) {
                return;
            }

            $storeId = self::storeOf($record, $get);

            $taken = Product::withoutGlobalScopes()
                ->withTrashed()
                ->where('store_id', $storeId)
                ->where($column, $value)
                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->exists();

            if ($taken) {
                $fail($column === 'sku' ? 'Another product in this store already has this SKU.' : 'Another product in this store already uses this URL.');
            }
        };
    }
}
