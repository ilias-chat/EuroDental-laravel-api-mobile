<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = ['name', 'description', 'price', 'service_category_id'];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    /**
     * Category this service belongs to.
     */
    public function category()
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /**
     * Get the tasks that use this service
     */
    public function tasks()
    {
        return $this->belongsToMany(Task::class, 'task_services')->withPivot('price');
    }
}

