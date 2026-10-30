<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Notifications\LowStockNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class CheckLowStockItems extends Command
{
    protected $signature = 'inventory:check-low-stock';

    protected $description = 'Check for low stock items and send notifications';

    public function handle()
    {
        // Stock is held by a simple product or by each of a product's variants, never both.
        $lowStockProducts = Product::tracked()->withoutVariants()->where('inventory_count', '<=', DB::raw('low_stock_threshold'))->get();
        $lowStockVariants = ProductVariant::query()->available()->where('track_inventory', true)
            ->with('product')
            ->get()
            ->filter(fn (ProductVariant $variant) => $variant->product !== null && $variant->isLowStock());

        if ($lowStockProducts->isNotEmpty() || $lowStockVariants->isNotEmpty()) {
            // Admins are identified by role (there is no users.is_admin column).
            // whereHas (not the role() scope) so a not-yet-seeded role can't throw.
            $admins = User::whereHas('roles', function ($query) {
                $query->whereIn('name', ['super_admin', 'admin']);
            })->get();

            foreach ($lowStockProducts as $product) {
                Notification::send($admins, new LowStockNotification($product));
                $this->info("Low stock notification sent for product: {$product->name}");
            }

            foreach ($lowStockVariants as $variant) {
                Notification::send($admins, new LowStockNotification($variant->product, $variant));
                $this->info("Low stock notification sent for product: {$variant->product->name} ({$variant->label()})");
            }
        } else {
            $this->info('No low stock items found.');
        }
    }
}
