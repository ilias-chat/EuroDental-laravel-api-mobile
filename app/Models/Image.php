<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    protected $fillable = ['image_name'];

    public function clients() { return $this->hasMany(Client::class); }
public function users() { return $this->hasMany(User::class); }
public function products() { return $this->hasMany(Product::class); }
}
