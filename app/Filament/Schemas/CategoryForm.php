<?php

namespace App\Filament\Schemas;

use App\Models\ProductCategory;
use App\Services\StoreContext;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/** The category form both panels use. */
final class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Category')
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    StoreField::make(),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Set $set, Get $get, ?string $old, ?string $state, string $operation): void {
                            $slug = (string) ($get('slug') ?? '');
                            if ($operation === 'create' && ($slug === '' || $slug === Str::slug((string) $old))) {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),
                    TextInput::make('slug')
                        ->label('URL slug')
                        ->required()
                        ->alphaDash()
                        ->maxLength(255)
                        ->rule(fn (?ProductCategory $record, Get $get) => self::slugIsFreeInStore($record, $get)),
                    Select::make('parent_category_id')
                        ->label('Parent category')
                        ->placeholder('None — a top-level category')
                        ->options(fn (?ProductCategory $record, Get $get) => self::parentOptions($record, $get))
                        ->in(fn (?ProductCategory $record, Get $get) => array_keys(self::parentOptions($record, $get)))
                        ->disabled(fn (?ProductCategory $record) => $record?->children()->exists())
                        ->helperText(fn (?ProductCategory $record) => $record?->children()->exists()
                            ? 'This category has subcategories, so it stays top-level.'
                            : 'Two levels: departments and their subcategories.')
                        ->searchable(),
                    Textarea::make('description')->rows(3)->maxLength(2000)->columnSpanFull(),
                    // Raster only: an SVG served from the public disk can carry script.
                    FileUpload::make('image')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                        ->maxSize(4096)
                        ->disk('public')
                        ->directory('categories')
                        ->visibility('public')
                        ->columnSpanFull(),
                ]),
            Section::make('Search engine listing')
                ->columnSpanFull()
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextInput::make('meta_title')->maxLength(70)->helperText('Defaults to the category name.'),
                    Textarea::make('meta_description')->maxLength(160)->rows(2)->helperText('Defaults to the description.'),
                ]),
        ]);
    }

    /** Top-level categories in the same store, other than this one. */
    private static function parentOptions(?ProductCategory $record, Get $get): array
    {
        return ProductCategory::withoutGlobalScopes()
            ->where('store_id', $record?->store_id ?? $get('store_id') ?? StoreContext::forWrites())
            ->whereNull('parent_category_id')
            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function slugIsFreeInStore(?ProductCategory $record, Get $get): Closure
    {
        return function (string $attribute, $value, Closure $fail) use ($record, $get): void {
            $taken = ProductCategory::withoutGlobalScopes()
                ->where('store_id', $record?->store_id ?? $get('store_id') ?? StoreContext::forWrites())
                ->where('slug', $value)
                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->exists();

            if ($taken) {
                $fail('Another category in this store already uses this URL.');
            }
        };
    }
}
