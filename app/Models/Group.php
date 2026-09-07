<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
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
