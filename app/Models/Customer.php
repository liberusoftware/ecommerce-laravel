<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;
    use IsStoreScoped;
    use IsTenantModel;

    protected $table = 'customers';

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'address',
        'city',
        'state',
        'postal_code',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function review()
    {
        return $this->hasMany(ProductReview::class);
    }

    public function rating()
    {
        return $this->hasMany(ProductRating::class);
    }

    public function getTotalSpentAttribute(): float
    {
        return $this->orders()->where('payment_status', 'paid')->sum('total_amount');
    }

    public function getLifetimeValueAttribute(): float
    {
        return $this->total_spent;
    }

    public function getOrderCountAttribute(): int
    {
        return $this->orders()->count();
    }

    public function isVip(): bool
    {
        return $this->total_spent >= 1000 || $this->order_count >= 10;
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
