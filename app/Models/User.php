<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'profile_id',
        'city_id',
        'image_id',
        'phone_number',
        'address',
        'is_on_deployment',
    ];



    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_on_deployment' => 'boolean',
        ];
    }

    // User.php
    public function profile()
    {
        return $this->belongsTo(Profile::class);
    }
    
    public function image()
    {
        return $this->belongsTo(Image::class);
    }
    
    public function tasks()
    {
        return $this->hasMany(Task::class, 'technician_id');
    }

    public function taskEvents()
    {
        return $this->hasMany(TaskEvent::class, 'user_id');
    }
    
    public function city()
    {
        return $this->belongsTo(City::class);
    }
    
    public function pushTokens()
    {
        return $this->hasMany(PushToken::class);
    }
    
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }
    
    public function createdOrders()
    {
        return $this->hasMany(Order::class, 'created_by');
    }
    
    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }
    
    public function experiences()
    {
        return $this->hasMany(UserExperience::class)->orderBy('order')->orderBy('start_date', 'desc');
    }
    
    public function educations()
    {
        return $this->hasMany(UserEducation::class)->orderBy('order')->orderBy('start_date', 'desc');
    }
    
    public function certifications()
    {
        return $this->hasMany(UserCertification::class)->orderBy('order')->orderBy('issue_date', 'desc');
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function flagEvents()
    {
        return $this->hasMany(UserFlagEvent::class, 'user_id')->orderByDesc('created_at');
    }

    use HasApiTokens, Notifiable, HasFactory;

    
    public function getPermissions()
    {
        if (!$this->profile) {
            return [];
        }

        return $this->profile->permissions->pluck('code')->toArray();
    }

    public function getNameAttribute()
    {
        return trim("{$this->first_name} {$this->last_name}");
    }


        
}
