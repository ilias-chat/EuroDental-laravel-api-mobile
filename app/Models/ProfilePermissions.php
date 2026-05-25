<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfilePermission extends Model
{
    protected $table = 'profile_permission';

    protected $fillable = [
        'profile_id',
        'permission_id',
    ];

    public function profile()
    {
        return $this->belongsTo(Profile::class);
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }
}

