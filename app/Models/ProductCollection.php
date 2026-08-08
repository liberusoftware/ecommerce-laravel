<?php

namespace App\Models;

use App\Interfaces\Orderable;
use App\Traits\IsStoreScoped;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductCollection extends Model implements Orderable
{
    use HasFactory;
    use IsStoreScoped;
    use IsTenantModel;
    use SoftDeletes;

    protected $table = 'collections';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',

        // Fillable so the API write paths can stamp the creating admin's team.
        // No validator accepts it from request input, so it cannot be set by a
        // caller — see Api\Concerns\OwnsTeamResources.
        'team_id',
    ];

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'collection_items', 'collection_id')
            ->withPivot('quantity');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
