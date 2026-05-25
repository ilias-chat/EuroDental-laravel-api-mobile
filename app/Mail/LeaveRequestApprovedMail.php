<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\LeaveRequest;

class LeaveRequestApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $leaveRequest;
    public $userName;
    public $startDate;
    public $endDate;
    public $leaveType;
    public $reviewedByName;

    /**
     * Create a new message instance.
     */
    public function __construct(LeaveRequest $leaveRequest)
    {
        // Ensure relationships are loaded
        if (!$leaveRequest->relationLoaded('user')) {
            $leaveRequest->load('user');
        }
        if (!$leaveRequest->relationLoaded('reviewer')) {
            $leaveRequest->load('reviewer');
        }
        
        $this->leaveRequest = $leaveRequest;
        $this->userName = $leaveRequest->user->first_name . ' ' . $leaveRequest->user->last_name;
        $this->startDate = $leaveRequest->start_date->format('d/m/Y');
        $this->endDate = $leaveRequest->end_date->format('d/m/Y');
        $this->leaveType = $this->getLeaveTypeLabel($leaveRequest->leave_type);
        $this->reviewedByName = $leaveRequest->reviewer 
            ? $leaveRequest->reviewer->first_name . ' ' . $leaveRequest->reviewer->last_name 
            : 'Administrateur';
    }

    public function build()
    {
        return $this->subject('Demande de congé approuvée')
                    ->view('emails.leave_request_approved')
                    ->with([
                        'userName' => $this->userName,
                        'startDate' => $this->startDate,
                        'endDate' => $this->endDate,
                        'leaveType' => $this->leaveType,
                        'reviewedByName' => $this->reviewedByName,
                        'appUrl' => config('app.url'),
                    ]);
    }

    private function getLeaveTypeLabel($type)
    {
        $labels = [
            'vacation' => 'Vacances',
            'sick_leave' => 'Maladie',
            'personal' => 'Personnel',
            'other' => 'Autre'
        ];
        return $labels[$type] ?? $type;
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}

