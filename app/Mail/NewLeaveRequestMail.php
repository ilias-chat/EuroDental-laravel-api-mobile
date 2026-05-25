<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\LeaveRequest;

class NewLeaveRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public $leaveRequest;
    public $userName;
    public $startDate;
    public $endDate;
    public $leaveType;
    public $description;

    /**
     * Create a new message instance.
     */
    public function __construct(LeaveRequest $leaveRequest)
    {
        // Ensure user relationship is loaded
        if (!$leaveRequest->relationLoaded('user')) {
            $leaveRequest->load('user');
        }
        
        $this->leaveRequest = $leaveRequest;
        $this->userName = $leaveRequest->user->first_name . ' ' . $leaveRequest->user->last_name;
        $this->startDate = $leaveRequest->start_date->format('d/m/Y');
        $this->endDate = $leaveRequest->end_date->format('d/m/Y');
        $this->leaveType = $this->getLeaveTypeLabel($leaveRequest->leave_type);
        $this->description = $leaveRequest->description;
    }

    public function build()
    {
        return $this->subject('Nouvelle demande de congé - ' . $this->userName)
                    ->view('emails.new_leave_request')
                    ->with([
                        'userName' => $this->userName,
                        'startDate' => $this->startDate,
                        'endDate' => $this->endDate,
                        'leaveType' => $this->leaveType,
                        'description' => $this->description,
                        'justificationMethod' => $this->getJustificationLabel($this->leaveRequest->justification_method),
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

    private function getJustificationLabel($method)
    {
        $labels = [
            'whatsapp' => 'WhatsApp',
            'email' => 'Email',
            'telegram' => 'Telegram',
            'other' => 'Autre'
        ];
        return $labels[$method] ?? $method;
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

