<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $fillable = ['brand'];
    
    public function products() { return $this->hasMany(Product::class, 'id_brand'); }
}
