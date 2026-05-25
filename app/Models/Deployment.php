<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deployment extends Model
{
    protected $fillable = [
        'title',
        'deployment_date',
        'responsible_id',
        'driver_id',
        'team_member_ids',
        'hosters',
        'description',
        'city_id',
    ];

    protected $casts = [
        'team_member_ids' => 'array',
        'hosters' => 'array',
        'deployment_date' => 'date',
    ];

    /**
     * Get the responsible user
     */
    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    /**
     * Get the driver user
     */
    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /**
     * Get the city
     */
    public function city()
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Get all tasks for this deployment
     */
    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    /**
     * Get all expenses for this deployment
     */
    public function expenses()
    {
        return $this->hasMany(DeploymentExpense::class);
    }

    /**
     * Get all events for this deployment (ordered by event_time or created_at)
     */
    public function events()
    {
        return $this->hasMany(DeploymentEvent::class)->orderByRaw('COALESCE(event_time, created_at) ASC');
    }

    /**
     * Get hoster users for this deployment
     */
    public function getHosters()
    {
        if (!$this->hosters || empty($this->hosters)) {
            return collect();
        }

        return User::whereIn('id', $this->hosters)->get();
    }

    /**
     * Get team members for this deployment
     */
    public function teamMembers()
    {
        if (!$this->team_member_ids || empty($this->team_member_ids)) {
            return collect();
        }
        
        return User::whereIn('id', $this->team_member_ids)->get();
    }

    /**
     * Get all users involved in this deployment (responsible, driver, and team members)
     */
    public function getAllTeamMembers()
    {
        $userIds = [];
        
        if ($this->responsible_id) {
            $userIds[] = $this->responsible_id;
        }
        
        if ($this->driver_id) {
            $userIds[] = $this->driver_id;
        }
        
        if ($this->team_member_ids && is_array($this->team_member_ids)) {
            $userIds = array_merge($userIds, $this->team_member_ids);
        }
        
        $userIds = array_unique($userIds);
        
        return User::whereIn('id', $userIds)->get();
    }
}

