<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeploymentEvent extends Model
{
    protected $fillable = [
        'deployment_id',
        'event_type',
        'user_id',
        'event_time',
    ];

    protected $casts = [
        'event_time' => 'datetime',
    ];

    public const TYPE_START = 'start';
    public const TYPE_END = 'end';
    public const TYPE_JOINED = 'joined';

    /**
     * Get the deployment
     */
    public function deployment()
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * Get the user (for joined events)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
