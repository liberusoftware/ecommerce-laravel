<?php

namespace Liberu\Ecommerce\Content\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    use HasFactory;
    
    protected $fillable = ['name', 'value', 'description'];

    protected $casts = [
        'value' => 'string',
    ];
}
