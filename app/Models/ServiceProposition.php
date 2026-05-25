<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceProposition extends Model
{
    protected $fillable = [
        'task_id',
        'proposed_by',
        'name',
        'status',
        'reviewed_by',
        'reviewed_at',
        'service_id'
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    /**
     * Get the task this proposition is for
     */
    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * Get the user who proposed this service
     */
    public function proposer()
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    /**
     * Get the user who reviewed this proposition
     */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Get the service created from this proposition (if approved)
     */
    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}
