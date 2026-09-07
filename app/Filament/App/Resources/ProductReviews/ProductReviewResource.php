<?php

namespace App\Filament\App\Resources\ProductReviews;

use App\Filament\App\Resources\ProductReviews\Pages\CreateProductReview;
use App\Filament\App\Resources\ProductReviews\Pages\EditProductReview;
use App\Filament\App\Resources\ProductReviews\Pages\ListProductReviews;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductReview;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductReviewResource extends Resource
{
    protected static ?string $model = ProductReview::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $modelLabel = 'Review';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Product')
                    ->required()
                    ->options(Product::pluck('name', 'id'))
                    ->reactive(),

                Select::make('customer_id')
                    ->label('Customer')
                    ->required()
                    ->options(Customer::pluck('first_name', 'id'))
                    ->reactive(),

                Textarea::make('comments')
                    ->label('Comments')
                    ->required()
                    ->maxLength(65535),

                // Moderation lives here now (ADR 0008). Without it the panel
                // lists reviews it cannot publish, and the only way to approve
                // one is the HTTP endpoint — a queue with no queue.
                Toggle::make('approved')
                    ->label('Published')
                    ->helperText('Unpublished reviews are not shown to shoppers.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.first_name')
                    ->label('Customer'),
                TextColumn::make('comments')
                    ->label('Comments'),
                IconColumn::make('approved')
                    ->label('Published')
                    ->boolean(),
            ])
            ->filters([
                // The moderation queue: what is waiting on somebody.
                TernaryFilter::make('approved')
                    ->label('Published'),
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
            'index' => ListProductReviews::route('/'),
            'create' => CreateProductReview::route('/create'),
            'edit' => EditProductReview::route('/{record}/edit'),
        ];
    }
}
