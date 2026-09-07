<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DownloadableProduct extends Model
{
    use HasFactory;
    use IsStoreScoped;

    protected $fillable = [
        'product_id',
        'file_url',
        'download_limit',
        'downloads_count',
        'expiration_time',
    ];

    protected $casts = [
        'expiration_time' => 'datetime',
        'download_limit' => 'integer',
        'downloads_count' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function isDownloadable(): bool
    {
        return $this->download_limit > $this->downloads_count
            && (! $this->expiration_time || $this->expiration_time->isFuture());
    }

    public function incrementDownloadCount(): void
    {
        $this->increment('downloads_count');
    }
}
