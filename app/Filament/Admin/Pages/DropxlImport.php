<?php

namespace App\Filament\Admin\Pages;

use App\Models\Store;
use App\Services\DropxlService;
use App\Support\StoreAccess;
use App\Support\TeamPermission;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class DropxlImport extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected string $view = 'filament.admin.pages.dropxl-import';

    protected static ?int $navigationSort = 9;

    protected static string|\UnitEnum|null $navigationGroup = 'Dropshipping';

    protected static ?string $title = 'DropXL Import';

    protected static ?string $navigationLabel = 'Import from DropXL';

    /** @var string|null Keyword to filter products */
    public ?string $keyword = null;

    /** @var string|null Selected DropXL category ID */
    public ?string $categoryId = null;

    /** @var array Products returned by the last search */
    public array $searchResults = [];

    /** @var array DropXL categories available for filtering */
    public array $categories = [];

    /** @var bool Whether a search has been performed */
    public bool $hasSearched = false;

    /** The store imported products are filed under: required, the platform spans every store. */
    public ?int $storeId = null;

    public static function canAccess(): bool
    {
        return StoreAccess::allows(auth()->user(), TeamPermission::CATALOGUE_MANAGE, null, 'create_product');
    }

    /** @return array<int, string> */
    public function getStores(): array
    {
        return Store::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    private function targetStore(): ?int
    {
        if ($this->storeId === null || ! array_key_exists($this->storeId, $this->getStores())) {
            Notification::make()->title('Choose the store to import into')->danger()->send();

            return null;
        }

        return $this->storeId;
    }

    public function mount(): void
    {
        $this->loadCategories();
    }

    /**
     * Load available DropXL categories for the category filter.
     */
    protected function loadCategories(): void
    {
        $service = app(DropxlService::class);
        $result = $service->getCategories();

        if ($result['success']) {
            $data = $result['data'];
            $items = $data['data'] ?? $data['categories'] ?? (is_array($data) && array_is_list($data) ? $data : []);

            if (is_array($items)) {
                $this->categories = collect($items)
                    ->mapWithKeys(fn ($item) => [$item['id'] => $item['name']])
                    ->all();
            }
        }
    }

    /**
     * Search DropXL products using the current keyword and category filters.
     */
    public function search(): void
    {
        $service = app(DropxlService::class);
        $result = $service->searchProducts(
            $this->keyword ?: null,
            $this->categoryId ?: null,
        );

        $this->hasSearched = true;

        if ($result['success']) {
            $data = $result['data'];
            $products = $data['data'] ?? $data['products'] ?? (is_array($data) && array_is_list($data) ? $data : []);
            $this->searchResults = is_array($products) ? $products : [];
        } else {
            $this->searchResults = [];

            Notification::make()
                ->title('Search Failed')
                ->body($result['message'] ?? 'Unable to fetch products from DropXL.')
                ->danger()
                ->send();
        }
    }

    /**
     * Import a single product from the current search results by its index.
     */
    public function importProduct(int $index): void
    {
        $dropxlProduct = $this->searchResults[$index] ?? null;

        if (! $dropxlProduct) {
            return;
        }

        if (($storeId = $this->targetStore()) === null) {
            return;
        }

        $service = app(DropxlService::class);
        $result = $service->importProduct($dropxlProduct, $storeId);

        if ($result['success']) {
            Notification::make()
                ->title('Product Imported')
                ->body("'{$dropxlProduct['name']}' has been imported successfully.")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Import Failed')
                ->body($result['message'] ?? 'Unable to import the product.')
                ->danger()
                ->send();
        }
    }

    /**
     * Bulk-import all DropXL products, optionally filtered by the current keyword.
     */
    public function importAllProducts(): void
    {
        if (($storeId = $this->targetStore()) === null) {
            return;
        }

        $service = app(DropxlService::class);
        $result = $service->importAll($storeId, $this->keyword ?: null);

        if ($result['success']) {
            Notification::make()
                ->title('Bulk Import Complete')
                ->body("Imported {$result['imported']} product(s). Failed: {$result['failed']}.")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Bulk Import Failed')
                ->body($result['message'] ?? 'Unable to complete bulk import.')
                ->danger()
                ->send();
        }
    }

    /**
     * Bulk-import all products for the selected DropXL category.
     */
    public function importByCategory(): void
    {
        if (! $this->categoryId) {
            Notification::make()
                ->title('No Category Selected')
                ->body('Please select a category in the search form before using this action.')
                ->warning()
                ->send();

            return;
        }

        $service = app(DropxlService::class);
        if (($storeId = $this->targetStore()) === null) {
            return;
        }

        $result = $service->importByCategory($storeId, $this->categoryId, $this->keyword ?: null);

        if ($result['success']) {
            Notification::make()
                ->title('Category Import Complete')
                ->body("Imported {$result['imported']} product(s). Failed: {$result['failed']}.")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Category Import Failed')
                ->body($result['message'] ?? 'Unable to import from the selected category.')
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importAll')
                ->label('Import All Products')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Import All DropXL Products')
                ->modalDescription('This will import every product from DropXL into your catalog. This may take a while.')
                ->action(fn () => $this->importAllProducts()),

            Action::make('importCategory')
                ->label('Import Category')
                ->icon('heroicon-o-folder-arrow-down')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Import Products by Category')
                ->modalDescription('Import all products from the category selected in the search form.')
                ->action(fn () => $this->importByCategory()),
        ];
    }
}
