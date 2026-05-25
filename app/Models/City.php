<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $fillable = ['name'];
    
    public function clients() { return $this->hasMany(Client::class); }
    public function users() { return $this->hasMany(User::class); }
}
