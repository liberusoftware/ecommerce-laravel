<?php

namespace App\Filament\App\Resources\Products;

use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\Products\Pages\EditProduct;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Models\FacebookConnection;
use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Select::make('category_id')
                    ->label('Category')
                    ->required()
                    ->relationship('category', 'name')
                    ->reactive(),

                TextInput::make('short_description')
                    ->maxLength(255)
                    ->columnSpanFull(),

                Textarea::make('long_description')
                    ->maxLength(65535)
                    ->columnSpanFull(),

                Grid::make()
                    ->schema([
                        Toggle::make('is_variable')
                            ->label('Variable'),
                        Toggle::make('is_grouped')
                            ->label('Grouped'),
                        Toggle::make('is_simple')
                            ->label('Simple'),
                    ]),

                // Security: do NOT use ->image(), which expands to
                // acceptedFileTypes(['image/*']) and therefore accepts
                // image/svg+xml. An SVG is an XML document that can carry
                // <script>; served same-origin from the public disk that is
                // stored XSS. List raster formats explicitly instead.
                //
                // acceptedFileTypes() is a *validation* control (the upload is
                // rejected), not a *serving* control -- it does not make the
                // storage origin safe. The durable fix is to serve user uploads
                // from a separate origin, or with Content-Disposition: attachment
                // + X-Content-Type-Options: nosniff. Not done here.
                FileUpload::make('featured_image')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                    // 4MB: comfortably fits a full-bleed product photo off a
                    // modern phone camera, while bounding what one upload costs
                    // us. Was previously unbounded.
                    ->maxSize(4096)
                    ->disk('public')
                    ->directory('products')
                    ->visibility('public')
                    ->label('Featured Image'),

                Toggle::make('list_on_facebook')
                    ->label('List on Facebook')
                    ->helperText('Pushes this product into your Meta Catalog. Needs a Facebook Catalog connection.')
                    ->visible(fn () => FacebookConnection::forTeam(Filament::getTenant()?->id) !== null),

                Repeater::make('images')
                    ->relationship('images')
                    ->schema([
                        // Same SVG/XSS reasoning as featured_image above.
                        FileUpload::make('image')
                            ->hiddenLabel()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                            ->maxSize(4096)
                            ->disk('public')
                            ->directory('products')
                            ->visibility('public'),

                    ]),

                // Forms\Components\TextInput::make('meta_title')
                //     ->label('Meta Title')
                //     ->maxLength(60)
                //     ->helperText('Recommended length: 50-60 characters'),

                // Forms\Components\Textarea::make('meta_description')
                //     ->label('Meta Description')
                //     ->maxLength(160)
                //     ->helperText('Recommended length: 150-160 characters'),

                // Forms\Components\TagsInput::make('meta_keywords')
                //     ->label('Meta Keywords')
                //     ->helperText('Enter keywords separated by commas'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('featured_image')
                    ->label('Featured Image'),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                // Tables\Columns\TextColumn::make('short_description')
                //     ->label('Short Description'),
                // Tables\Columns\TextColumn::make('long_description')
                //     ->label('Long Description'),
                TextColumn::make('category.name')
                    ->label('Category'),
                // Tables\Columns\TextColumn::make('is_variable')
                //     ->label('Variable'),
                // Tables\Columns\TextColumn::make('is_grouped')
                //     ->label('Grouped'),
                // Tables\Columns\TextColumn::make('is_simple')
                //     ->label('Simple'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
