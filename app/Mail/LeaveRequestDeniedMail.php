<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\LeaveRequest;

class LeaveRequestDeniedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $leaveRequest;
    public $userName;
    public $startDate;
    public $endDate;
    public $leaveType;
    public $rejectionReason;

    /**
     * Create a new message instance.
     */
    public function __construct(LeaveRequest $leaveRequest)
    {
        // Ensure relationships are loaded
        if (!$leaveRequest->relationLoaded('user')) {
            $leaveRequest->load('user');
        }
        
        $this->leaveRequest = $leaveRequest;
        $this->userName = $leaveRequest->user->first_name . ' ' . $leaveRequest->user->last_name;
        $this->startDate = $leaveRequest->start_date->format('d/m/Y');
        $this->endDate = $leaveRequest->end_date->format('d/m/Y');
        $this->leaveType = $this->getLeaveTypeLabel($leaveRequest->leave_type);
        $this->rejectionReason = $leaveRequest->rejection_reason;
    }

    public function build()
    {
        return $this->subject('Demande de congé refusée')
                    ->view('emails.leave_request_denied')
                    ->with([
                        'userName' => $this->userName,
                        'startDate' => $this->startDate,
                        'endDate' => $this->endDate,
                        'leaveType' => $this->leaveType,
                        'rejectionReason' => $this->rejectionReason,
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

