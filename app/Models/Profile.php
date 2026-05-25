<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    protected $fillable = ['profile_name', 'sort_order'];

    public function users()
    {
        return $this->hasMany(User::class);
    }
    
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'profile_permission');
    }
    
}
