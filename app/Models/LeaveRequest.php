<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class LeaveRequest extends Model
{
    protected $fillable = [
        'user_id',
        'start_date',
        'end_date',
        'leave_type',
        'description',
        'status',
        'justification_method',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Get the user who made this leave request
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who reviewed this leave request
     */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Check if request is pending
     */
    public function isPending()
    {
        return $this->status === 'waiting';
    }

    /**
     * Check if request is accepted
     */
    public function isAccepted()
    {
        return $this->status === 'accepted';
    }

    /**
     * Check if request can be cancelled
     */
    public function canBeCancelled()
    {
        return $this->status === 'accepted' && $this->start_date > Carbon::today();
    }

    /**
     * Check if request can be edited
     */
    public function canBeEdited()
    {
        return $this->status === 'waiting';
    }

    /**
     * Scope to get pending requests
     */
    public function scopePending($query)
    {
        return $query->where('status', 'waiting');
    }

    /**
     * Scope to get active requests (accepted or on_leave within date range)
     */
    public function scopeActive($query, $startDate = null, $endDate = null)
    {
        $startDate = $startDate ? Carbon::parse($startDate) : Carbon::today();
        $endDate = $endDate ? Carbon::parse($endDate) : Carbon::today();

        return $query->whereIn('status', ['accepted', 'on_leave'])
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                  ->orWhereBetween('end_date', [$startDate, $endDate])
                  ->orWhere(function ($q2) use ($startDate, $endDate) {
                      $q2->where('start_date', '<=', $startDate)
                         ->where('end_date', '>=', $endDate);
                  });
            });
    }
}
