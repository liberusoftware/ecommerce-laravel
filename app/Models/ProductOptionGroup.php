<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A set of choices a shopper makes for a product: "Size", "Extras". Shared by any of the store's products. */
class ProductOptionGroup extends Model
{
    use IsStoreScoped;
    use IsTenantModel;

    protected $fillable = ['name', 'min_select', 'max_select'];

    protected $casts = [
        'min_select' => 'integer',
        'max_select' => 'integer',
    ];

    public function choices(): HasMany
    {
        return $this->hasMany(ProductOptionChoice::class)->orderBy('position')->orderBy('id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_option_group_product')->withPivot('position');
    }

    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }

    public function allowsOne(): bool
    {
        return $this->max_select === 1;
    }
}
