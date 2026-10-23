<?php

namespace App\Filament\App\Resources\ProductOptionGroups;

use App\Filament\App\Resources\ProductOptionGroups\Pages\CreateProductOptionGroup;
use App\Filament\App\Resources\ProductOptionGroups\Pages\EditProductOptionGroup;
use App\Filament\App\Resources\ProductOptionGroups\Pages\ListProductOptionGroups;
use App\Filament\Schemas\StoreField;
use App\Models\ProductOptionGroup;
use App\Services\StoreContext;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Choices a shopper makes for a product — sizes, extras, removals — defined once
 * per store and attached to any of its products. Registered in both panels.
 */
class ProductOptionGroupResource extends Resource
{
    protected static ?string $model = ProductOptionGroup::class;

    protected static ?string $modelLabel = 'option group';

    protected static ?string $slug = 'option-groups';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Option group')
                ->columnSpanFull()
                ->columns(3)
                ->schema([
                    StoreField::make(),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Shown to shoppers, e.g. "Size" or "Extras".'),
                    TextInput::make('min_select')
                        ->label('Minimum choices')
                        ->integer()
                        ->required()
                        ->default(0)
                        ->minValue(0)
                        ->live()
                        ->helperText('0 makes the group optional.'),
                    TextInput::make('max_select')
                        ->label('Maximum choices')
                        ->integer()
                        ->nullable()
                        ->minValue(fn (Get $get) => max(1, (int) $get('min_select')))
                        ->helperText('1 means pick one. Leave empty for no limit.'),
                ]),
            Section::make('Choices')
                ->columnSpanFull()
                ->schema([
                    Repeater::make('choices')
                        ->hiddenLabel()
                        ->relationship('choices')
                        ->orderColumn('position')
                        ->reorderable()
                        ->minItems(1)
                        ->columns(4)
                        ->addActionLabel('Add choice')
                        ->schema([
                            TextInput::make('name')->required()->maxLength(255),
                            TextInput::make('price_adjustment')
                                ->label('Price change')
                                ->numeric()
                                ->required()
                                ->default(0)
                                ->helperText('Added to the product price; may be negative.'),
                            Toggle::make('is_default')->label('Selected by default'),
                            Toggle::make('is_available')->label('Available')->default(true),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['choices', 'products']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('store.name')->label('Store')->sortable()->visible(fn () => StoreContext::isPlatform()),
                TextColumn::make('min_select')->label('Min')->numeric(),
                TextColumn::make('max_select')->label('Max')->placeholder('No limit'),
                TextColumn::make('choices_count')->label('Choices')->numeric(),
                TextColumn::make('products_count')->label('Products')->numeric(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->modalDescription('Products lose this group. Past orders keep the choices made.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductOptionGroups::route('/'),
            'create' => CreateProductOptionGroup::route('/create'),
            'edit' => EditProductOptionGroup::route('/{record}/edit'),
        ];
    }
}
