<?php

namespace Liberu\Ecommerce\Customer\Models;

use Liberu\Ecommerce\Customer\Traits\IsStoreScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    use HasFactory;
    use IsStoreScoped;

    protected $table = 'groups';

    protected $fillable = [
        'name',
        'discount',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
