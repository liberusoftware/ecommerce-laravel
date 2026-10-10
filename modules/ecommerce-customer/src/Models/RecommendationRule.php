<?php

namespace Liberu\Ecommerce\Customer\Models;

use Liberu\Ecommerce\Customer\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecommendationRule extends Model
{
    use HasFactory, IsTenantModel;

    protected $fillable = [
        'name',
        'type',
        'configuration',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'configuration' => 'array',
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    public function recommendations(): HasMany
    {
        return $this->hasMany(ProductRecommendation::class, 'rule_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('priority', 'desc');
    }
}
