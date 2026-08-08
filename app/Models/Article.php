<?php

namespace App\Models;

use App\Traits\IsStoreScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasFactory;
    use IsStoreScoped;

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
