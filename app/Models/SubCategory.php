<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubCategory extends Model
{
    protected $fillable = ['sub_category', 'category_id'];
    
    public function category() { return $this->belongsTo(Category::class); }
    public function products() { return $this->hasMany(Product::class, 'id_sub_category'); }
}
