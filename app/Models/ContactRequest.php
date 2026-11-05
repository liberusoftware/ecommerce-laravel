<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A message sent through a storefront's contact form, kept whether or not the mail went out. */
class ContactRequest extends Model
{
    use IsStoreScoped;
    use IsTenantModel;

    protected $fillable = ['name', 'email', 'subject', 'message'];

    protected $casts = ['read_at' => 'datetime'];

    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }
}
