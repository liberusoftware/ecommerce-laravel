<?php

namespace App\Filament\Schemas;

use App\Models\FacebookConnection;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductOptionGroup;
use App\Models\TaxClass;
use App\Services\ProductVariantService;
use App\Services\StoreContext;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
                        self::variants(),
                        self::productOptions(),
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
                    ->helperText(fn (Get $get) => self::hasVariants($get) ? 'Optional for the product as a whole; each variant has its own.' : null)
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
                    ->disabled(fn (Get $get) => self::hasVariants($get))
                    ->helperText(fn (Get $get) => self::hasVariants($get) ? 'Products with variants are sold at a fixed price.' : null)
                    ->live()
                    ->columnSpanFull(),
                TextInput::make('price')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(99999999)
                    ->prefix($currency)
                    ->required(fn (Get $get) => $get('pricing_type') === 'fixed' && ! self::hasVariants($get))
                    ->visible(fn (Get $get) => $get('pricing_type') === 'fixed' && ! self::hasVariants($get))
                    ->helperText('New variants start at this price.')
                    ->live(onBlur: true),
                TextInput::make('sale_price')
                    ->numeric()
                    ->minValue(0)
                    ->prefix($currency)
                    ->lt('price')
                    ->helperText('Leave empty when not on sale. Shoppers see the price struck through.')
                    ->visible(fn (Get $get) => $get('pricing_type') === 'fixed' && ! self::hasVariants($get)),
                Callout::make('Priced per variant')
                    ->description('Each variant below has its own selling price. Shoppers see the range, and pay the price of the variant they choose.')
                    ->info()
                    ->visible(fn (Get $get) => self::hasVariants($get))
                    ->columnSpanFull(),
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
                Callout::make('Stocked per variant')
                    ->description('Each variant below counts its own stock; the product has none of its own while it has variants.')
                    ->info()
                    ->visible(fn (Get $get) => self::hasVariants($get))
                    ->columnSpanFull(),
                Toggle::make('track_inventory')
                    ->label('Track stock')
                    ->default(fn (Get $get) => ! $get('is_downloadable'))
                    ->live()
                    ->hidden(fn (Get $get) => self::hasVariants($get))
                    ->helperText('Off sells any quantity: for made-to-order goods. Downloads start untracked.')
                    ->columnSpanFull(),
                TextInput::make('inventory_count')
                    ->label('Stock')
                    ->visible(fn (Get $get) => (bool) $get('track_inventory') && ! self::hasVariants($get))
                    ->required()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    // Changes after creation go through the stock actions, which log them.
                    ->disabledOn('edit')
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Use “Adjust stock” on the product list; adjustments are logged.' : null),
                TextInput::make('low_stock_threshold')
                    ->label('Low-stock alert at')
                    ->helperText(fn (Get $get) => self::hasVariants($get) ? 'Applies to each variant.' : null)
                    ->visible(fn (Get $get) => (bool) $get('track_inventory') || self::hasVariants($get))
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

    private static function variants(): Section
    {
        $currency = fn () => Money::currency();

        return Section::make('Variants')
            ->description('Use variants when shoppers choose between versions of this product, such as colours, sizes or storage, and each version has its own SKU, price or stock. Leave this empty for a product sold in one version.')
            ->collapsible()
            ->collapsed(fn (Get $get) => ! self::hasVariants($get) && blank($get('variant_attributes')))
            ->visible(fn (Get $get) => $get('pricing_type') === 'fixed')
            ->schema([
                Repeater::make('variant_attributes')
                    ->label('1. What do the versions differ by?')
                    ->helperText('Add an attribute such as Colour, then type each of its values and press Enter.')
                    ->addActionLabel('Add attribute')
                    ->defaultItems(0)
                    ->reorderable()
                    ->columns(3)
                    ->schema([
                        TextInput::make('name')
                            ->label('Attribute')
                            ->placeholder('Colour')
                            ->required()
                            ->maxLength(50),
                        TagsInput::make('values')
                            ->placeholder('Black, White, Blue…')
                            ->required()
                            ->reorderable()
                            ->splitKeys([','])
                            ->columnSpan(2),
                    ]),
                Actions::make([
                    Action::make('generateVariants')
                        ->label(fn (Get $get) => self::hasVariants($get) ? '2. Update variants' : '2. Generate variants')
                        ->icon('heroicon-o-squares-plus')
                        ->action(function (Get $get, Set $set): void {
                            try {
                                $rows = app(ProductVariantService::class)->generate(
                                    $get('variant_attributes'),
                                    $get('variants') ?? [],
                                    ['price' => $get('price'), 'track_inventory' => ! $get('is_downloadable')],
                                );
                            } catch (ValidationException $e) {
                                Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                                return;
                            }

                            // Fresh keys for new rows, so a later regeneration cannot collide with them.
                            $set('variants', collect($rows)->mapWithKeys(fn (array $row, $key) => [str_starts_with((string) $key, 'new-') ? (string) Str::uuid() : $key => $row])->all());
                        }),
                    Action::make('setVariantPrices')
                        ->label('Set every price')
                        ->icon('heroicon-o-currency-dollar')
                        ->color('gray')
                        ->visible(fn (Get $get) => self::hasVariants($get))
                        ->schema([
                            TextInput::make('price')->numeric()->required()->minValue(0)->maxValue(99999999)->prefix($currency),
                        ])
                        ->action(fn (array $data, Get $get, Set $set) => $set('variants', collect($get('variants'))
                            ->map(fn (array $row) => ['price' => $data['price']] + $row)->all())),
                    Action::make('fillVariantSkus')
                        ->label('Fill empty SKUs')
                        ->icon('heroicon-o-hashtag')
                        ->color('gray')
                        ->visible(fn (Get $get) => self::hasVariants($get))
                        ->action(fn (Get $get, Set $set) => $set('variants', collect($get('variants'))
                            ->map(fn (array $row) => filled($row['sku'] ?? null) ? $row : ['sku' => self::variantSku($get('sku') ?: $get('slug') ?: $get('name'), $row)] + $row)
                            ->all())),
                ])->key('variantActions'),
                Repeater::make('variants')
                    ->label(fn (Get $get) => '3. Set each variant\'s SKU, price and stock ('.self::variantSummary($get('variants') ?? []).')')
                    ->helperText('Every combination is listed. Remove a row for a combination you do not sell, or switch it off to stop selling it for now. Saving applies your changes.')
                    ->addable(false)
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->live()
                    ->table([
                        TableColumn::make('Variant'),
                        TableColumn::make('SKU'),
                        TableColumn::make('Price')->markAsRequired(),
                        TableColumn::make('Track stock'),
                        TableColumn::make('Stock'),
                        TableColumn::make('On sale'),
                    ])
                    ->schema([
                        Hidden::make('id'),
                        Hidden::make('attribute_values'),
                        TextInput::make('label')->disabled()->dehydrated(false),
                        TextInput::make('sku')->maxLength(100),
                        TextInput::make('price')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(99999999)
                            ->prefix($currency),
                        Toggle::make('track_inventory')->default(true)->live(),
                        TextInput::make('inventory_count')
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->visible(fn (Get $get) => (bool) $get('track_inventory'))
                            // Like product stock: set when created, adjusted (and logged) afterwards.
                            ->disabled(fn (Get $get) => filled($get('id')))
                            ->dehydrated(fn (Get $get) => blank($get('id'))),
                        Toggle::make('is_active')->default(true),
                    ]),
                Callout::make('Changing stock on an existing variant')
                    ->description('Use “Adjust stock” on the product list. Every adjustment is logged.')
                    ->color('gray')
                    ->visible(fn (Get $get, string $operation) => $operation === 'edit' && self::hasVariants($get)),
            ]);
    }

    private static function productOptions(): Section
    {
        return Section::make('Product options')
            ->description('Optional extras shoppers can add, such as gift wrapping, a protective case or an extended warranty. Options adjust the price but have no SKU or stock of their own. Manage the reusable sets under Product options.')
            ->collapsible()
            ->collapsed(fn (?Product $record) => $record === null || $record->optionGroups()->doesntExist())
            ->hidden(fn (Get $get) => $get('pricing_type') === 'donation')
            ->schema([
                // Only this product's own store's groups, offered and accepted.
                Select::make('optionGroups')
                    ->label('Options offered')
                    ->multiple()
                    ->relationship('optionGroups', 'name', modifyQueryUsing: fn ($query, Get $get, ?Product $record) => $query
                        ->where('product_option_groups.store_id', self::storeOf($record, $get)))
                    ->rule(fn (Get $get, ?Product $record) => function (string $attribute, $value, Closure $fail) use ($get, $record): void {
                        $ids = array_map('intval', (array) $value);
                        $own = ProductOptionGroup::withoutGlobalScopes()->whereKey($ids)->where('store_id', self::storeOf($record, $get))->count();
                        if ($own !== count($ids)) {
                            $fail('Product options must belong to this product\'s store.');
                        }
                    })
                    ->preload()
                    ->hidden(fn (Get $get) => $get('pricing_type') === 'donation'),
            ]);
    }

    /** "TEE" + Size S, Colour Black → "TEE-S-BLACK". The service still enforces uniqueness on save. */
    private static function variantSku(?string $base, array $row): string
    {
        $values = array_column($row['attribute_values'] ?? [], 'value');

        return Str::upper(Str::slug(implode(' ', [Str::limit((string) $base, 40, ''), ...$values])));
    }

    private static function variantSummary(array $rows): string
    {
        $onSale = collect($rows)->filter(fn (array $row) => (bool) ($row['is_active'] ?? true))->count();

        return count($rows).' '.Str::plural('variant', count($rows)).", {$onSale} on sale";
    }

    private static function hasVariants(Get $get): bool
    {
        return filled($get('variants'));
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
                TextEntry::make('facebook_status')
                    ->label('Catalog status')
                    ->state(fn (?Product $record) => self::facebookStatus($record))
                    ->visible(fn (?Product $record) => $record?->facebookListings()->exists() ?? false),
            ]);
    }

    /** Each Catalog item's status, with Meta's first rejection reason. */
    public static function facebookStatus(?Product $record): array
    {
        return $record?->facebookListings()->with('variant')->get()->map(function ($listing) {
            $label = $listing->product_variant_id ? ($listing->variant?->label() ?: 'Removed variant') : 'Product';
            $error = $listing->errors[0]['message'] ?? $listing->errors['message'] ?? null;

            return $label.': '.str_replace('_', ' ', $listing->status).($error ? ' — '.$error : '');
        })->all() ?? [];
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
