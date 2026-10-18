<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Search / Filter Form --}}
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                Search DropXL Products
            </h3>

            <div class="flex flex-wrap gap-4 items-end">
                <div class="min-w-[200px]">
                    <label for="import-store" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Import into store
                    </label>
                    <select id="import-store" wire:model="storeId" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                        <option value="">Choose a store…</option>
                        @foreach ($this->getStores() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Keyword --}}
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Keyword
                    </label>
                    <input
                        type="text"
                        wire:model="keyword"
                        wire:keydown.enter="search"
                        placeholder="Search by keyword…"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:ring-primary-500 focus:border-primary-500"
                    />
                </div>

                {{-- Category Filter --}}
                @if(!empty($categories))
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Category
                    </label>
                    <select
                        wire:model="categoryId"
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:ring-primary-500 focus:border-primary-500"
                    >
                        <option value="">All Categories</option>
                        @foreach($categories as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                {{-- Search Button --}}
                <div>
                    <button
                        wire:click="search"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-primary-600 text-white text-sm font-medium hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="search">
                            <x-heroicon-o-magnifying-glass class="w-4 h-4 inline-block" />
                            Search
                        </span>
                        <span wire:loading wire:target="search">Searching…</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- Results Table --}}
        @if($hasSearched)
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
            <div class="flex justify-between items-center px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                    Results
                    <span class="ml-2 text-sm font-normal text-gray-500 dark:text-gray-400">
                        ({{ count($searchResults) }} product(s))
                    </span>
                </h3>
            </div>

            @if(empty($searchResults))
            <div class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                No products found. Try a different keyword or category.
            </div>
            @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left text-gray-700 dark:text-gray-300">
                    <thead class="text-xs uppercase bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-6 py-3">Product</th>
                            <th class="px-6 py-3">SKU</th>
                            <th class="px-6 py-3">Category</th>
                            <th class="px-6 py-3">Price</th>
                            <th class="px-6 py-3">Stock</th>
                            <th class="px-6 py-3 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($searchResults as $index => $product)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                            {{-- Product name + thumbnail --}}
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    @if(!empty($product['image_url']) || !empty($product['image']))
                                    <img
                                        src="{{ $product['image_url'] ?? $product['image'] }}"
                                        alt="{{ $product['name'] ?? '' }}"
                                        class="w-10 h-10 rounded object-cover flex-shrink-0"
                                    />
                                    @else
                                    <div class="w-10 h-10 rounded bg-gray-100 dark:bg-gray-600 flex items-center justify-center flex-shrink-0">
                                        <x-heroicon-o-photo class="w-5 h-5 text-gray-400" />
                                    </div>
                                    @endif
                                    <span class="font-medium text-gray-900 dark:text-white">
                                        {{ $product['name'] ?? '—' }}
                                    </span>
                                </div>
                            </td>

                            <td class="px-6 py-4 font-mono text-xs text-gray-500 dark:text-gray-400">
                                {{ $product['sku'] ?? '—' }}
                            </td>

                            <td class="px-6 py-4">
                                {{ $product['category'] ?? '—' }}
                            </td>

                            <td class="px-6 py-4">
                                ${{ number_format($product['price'] ?? 0, 2) }}
                            </td>

                            <td class="px-6 py-4">
                                {{ $product['stock'] ?? $product['inventory'] ?? '—' }}
                            </td>

                            <td class="px-6 py-4 text-right">
                                <button
                                    wire:click="importProduct({{ $index }})"
                                    wire:loading.attr="disabled"
                                    wire:target="importProduct({{ $index }})"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs rounded-lg bg-success-600 text-white hover:bg-success-700 focus:outline-none focus:ring-2 focus:ring-success-500 disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="importProduct({{ $index }})">Import</span>
                                    <span wire:loading wire:target="importProduct({{ $index }})">…</span>
                                </button>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
        @endif

    </div>
</x-filament-panels::page>
