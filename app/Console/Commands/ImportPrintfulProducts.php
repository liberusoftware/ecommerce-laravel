<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Printful\PrintfulCatalogImporter;
use Illuminate\Console\Command;

class ImportPrintfulProducts extends Command
{
    protected $signature = 'printful:import-products
                            {--product= : One Printful sync product id, rather than the whole store}
                            {--onto= : Map that product onto this existing local product id}';

    protected $description = 'Import the Printful store\'s sync products as made-to-order products with variants';

    public function handle(PrintfulCatalogImporter $importer): int
    {
        if (! $importer->isConfigured()) {
            $this->info('Printful import skipped: PRINTFUL_API_TOKEN is not set.');

            return self::SUCCESS;
        }

        if ($id = $this->option('product')) {
            $onto = $this->option('onto') ? Product::findOrFail($this->option('onto')) : null;
            $product = $importer->importProduct($id, $onto);
            $this->info("Imported {$product->name} with {$product->variants()->count()} variants.");

            return self::SUCCESS;
        }

        $result = $importer->importAll();
        $this->info("Printful import: {$result['imported']} imported, {$result['failed']} failed.");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
