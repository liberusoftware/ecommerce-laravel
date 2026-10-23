<?php

namespace App\Console\Commands;

use App\Models\Product;
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
        $lowStockProducts = Product::tracked()->where('inventory_count', '<=', DB::raw('low_stock_threshold'))->get();

        if ($lowStockProducts->isNotEmpty()) {
            // Admins are identified by role (there is no users.is_admin column).
            // whereHas (not the role() scope) so a not-yet-seeded role can't throw.
            $admins = User::whereHas('roles', function ($query) {
                $query->whereIn('name', ['super_admin', 'admin']);
            })->get();

            foreach ($lowStockProducts as $product) {
                Notification::send($admins, new LowStockNotification($product));
                $this->info("Low stock notification sent for product: {$product->name}");
            }
        } else {
            $this->info('No low stock items found.');
        }
    }
}
