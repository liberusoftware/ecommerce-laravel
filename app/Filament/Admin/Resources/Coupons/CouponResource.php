<?php

namespace App\Filament\Admin\Resources\Coupons;

use App\Filament\Admin\Resources\Coupons\Pages\CreateCoupon;
use App\Filament\Admin\Resources\Coupons\Pages\EditCoupon;
use App\Filament\Admin\Resources\Coupons\Pages\ListCoupons;
use App\Models\Coupon;
use App\Services\StoreContext;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class CouponResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    // Unique within the store this coupon will belong to, which
                    // is the grain the index uses. A validation rule is a query
                    // builder query, so no Eloquent scope reaches it — left
                    // bare it tells a merchant their code is taken when what is
                    // taken is a competitor's, and the database would have
                    // accepted the row.
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('store_id', StoreContext::forWrites()),
                    )
                    ->maxLength(255),
                Select::make('type')
                    ->required()
                    ->options([
                        'percentage' => 'Percentage',
                        'fixed' => 'Fixed Amount',
                    ]),
                TextInput::make('value')
                    ->required()
                    ->numeric()
                    ->label(fn ($get) => $get('type') === 'percentage' ? 'Discount Percentage' : 'Discount Amount'),
                DateTimePicker::make('valid_from')
                    ->required(),
                DateTimePicker::make('valid_until')
                    ->required(),
                TextInput::make('max_uses')
                    ->numeric()
                    ->nullable(),
                TextInput::make('min_purchase_amount')
                    ->numeric()
                    ->nullable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('type'),
                TextColumn::make('value'),
                TextColumn::make('valid_from')->date(),
                TextColumn::make('valid_until')->date(),
                // Counted per row rather than with `counts('orders')`. The
                // relation is constrained to the coupon's own store — codes are
                // unique per store, so the code alone names several coupons —
                // and `withCount` builds it from a blank model instance, whose
                // store is null. That subquery returns a number, and a wrong
                // number in a usage column is worse than a slow one.
                TextColumn::make('uses_count')
                    ->label('Uses')
                    ->state(fn (Coupon $record): int => $record->orders()->count()),
                TextColumn::make('max_uses'),
            ])
            ->filters([
                Filter::make('active')
                    ->query(fn (Builder $query): Builder => $query->where('valid_until', '>=', now())),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                DeleteBulkAction::make(),
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
            'index' => ListCoupons::route('/'),
            'create' => CreateCoupon::route('/create'),
            'edit' => EditCoupon::route('/{record}/edit'),
        ];
    }
}
