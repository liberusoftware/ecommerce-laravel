<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $fillable = ['name'];

    public function vehicleModels()
    {
        return $this->hasMany(VehicleModel::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

}
