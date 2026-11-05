<?php

namespace App\Http\Controllers;

use App\Models\DownloadableProduct;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DownloadController extends Controller
{
    public function generateSecureLink(Request $request, $product)
    {
        $downloadableProduct = DownloadableProduct::where('product_id', $product)
            ->firstOrFail();

        if (! $downloadableProduct->isDownloadable() || ! $this->authorizeDownload($request->user(), $downloadableProduct)) {
            abort(403, 'Download limit reached or not authorized.');
        }

        $temporaryUrl = Storage::disk('local')->temporaryUrl(
            $downloadableProduct->file_url,
            now()->addMinutes(5)
        );

        return response()->json([
            'url' => $temporaryUrl,
            'expires_in' => 300, // 5 minutes in seconds
            'downloads_remaining' => $downloadableProduct->download_limit === null ? null : $downloadableProduct->download_limit - $downloadableProduct->downloads_count,
        ]);
    }

    public function serveFile(Request $request, $product)
    {
        $downloadableProduct = DownloadableProduct::where('product_id', $product)
            ->firstOrFail();

        // Free products carry no purchase — serve without per-order limits.
        if ($downloadableProduct->product->isFree()) {
            return $this->streamFile($downloadableProduct);
        }

        // Paid products are gated PER PURCHASE: each buyer's own order line item
        // carries its download window (30-day expiry) and its own counter, so one
        // buyer can't exhaust the allowance for everyone (the product-global
        // download_limit is the per-purchase cap, not a shared pool).
        $item = $this->resolvePurchasedItem($request->user(), $product, $request->query('token'));

        if (! $item || ! $item->isDownloadValid()) {
            abort(403, 'This download link is invalid or has expired.');
        }

        $limit = $downloadableProduct->download_limit;
        if ($limit !== null && $item->download_count >= $limit) {
            abort(403, 'Download limit reached for this purchase.');
        }

        $item->increment('download_count');

        return $this->streamFile($downloadableProduct);
    }

    /**
     * The authenticated user's purchased line item for this product, from a paid
     * order. A token (from the emailed link) pins a specific purchase; otherwise
     * the most recent one is used.
     */
    private function resolvePurchasedItem($user, $productId, ?string $token): ?OrderItem
    {
        if (! $user) {
            return null;
        }

        return OrderItem::where('product_id', $productId)
            ->whereHas('order', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_COMPLETED]);
            })
            ->when($token, fn ($query) => $query->where('download_link', $token))
            ->latest()
            ->first();
    }

    private function streamFile(DownloadableProduct $downloadableProduct)
    {
        return Storage::disk('local')->download(
            $downloadableProduct->file_url,
            null,
            ['Content-Type' => Storage::disk('local')->mimeType($downloadableProduct->file_url)]
        );
    }

    private function authorizeDownload($user, DownloadableProduct $downloadableProduct)
    {
        // Allow download for free products without authentication
        if ($downloadableProduct->product->isFree()) {
            return true;
        }

        if (! $user) {
            return false;
        }

        // Check if user has purchased the product
        return $user->orders()
            ->whereHas('items', function ($query) use ($downloadableProduct) {
                $query->where('product_id', $downloadableProduct->product_id);
            })
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_COMPLETED])
            ->exists();
    }
}
