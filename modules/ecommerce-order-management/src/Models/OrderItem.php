<?php

namespace Liberu\Ecommerce\OrderManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;
       
    protected $table = 'order_items';

    protected $fillable = [
        'order_id',
        'product_id',
        'quantity',
        'price',
        'download_link',
        'download_expires_at',
        'download_count',
    ];

    protected $casts = [
        'download_expires_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Check if the download link is still valid
     */
    public function isDownloadValid(): bool
    {
        if (!$this->download_link || !$this->download_expires_at) {
            return false;
        }
        
        return $this->download_expires_at->isFuture();
    }
}
