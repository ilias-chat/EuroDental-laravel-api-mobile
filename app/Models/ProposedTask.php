<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProposedTask extends Model
{
    protected $fillable = [
        'task_name',
        'task_type',
        'client_id',
        'description',
        'proposed_by',
        'urgent',
        'status'
    ];

    protected $casts = [
        'urgent' => 'boolean',
    ];

    /**
     * Get the user who proposed this task
     */
    public function proposedBy()
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    /**
     * Get the client associated with this proposed task
     */
    public function client()
    {
        return $this->belongsTo(Client::class);
    }
}

