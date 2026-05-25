<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = ['code', 'label', 'group'];


    public function profiles()
    {
        return $this->belongsToMany(Profile::class, 'profile_permission');
    }

    public function profilePermissions()
    {
        return $this->hasMany(ProfilePermission::class);
    }
}

