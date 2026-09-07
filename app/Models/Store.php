<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A storefront's worth of commerce data, owned by a Team.
 *
 * Not to be confused with `App\Filament\Admin\Resources\Stores\StoreResource`,
 * which is a Team resource wearing the label "Store". That resource predates
 * this model and does not point at it.
 */
class Store extends Model
{
    use HasFactory;

    protected $fillable = ['team_id', 'name', 'slug'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }
}
