<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskFollower extends Model
{
    protected $fillable = ['task_id', 'user_id'];
}
