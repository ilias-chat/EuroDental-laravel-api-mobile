<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskEvent extends Model
{
    protected $fillable = [
        'task_id',
        'user_id',
        'event_type',
        'event_time',
        'city_id'
    ];

    protected $casts = [
        'event_time' => 'datetime'
    ];

    // Relationships
    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
