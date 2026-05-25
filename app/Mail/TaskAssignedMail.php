<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\Task;

class TaskAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $task;
    public $technicianName;
    public $clientName;
    public $taskDate;

    /**
     * Create a new message instance.
     */
    public function __construct(Task $task, $technicianName, $clientName, $taskDate)
    {
        $this->task = $task;
        $this->technicianName = $technicianName;
        $this->clientName = $clientName;
        $this->taskDate = $taskDate;
    }

    public function build()
    {
        $urgentText = $this->task->urgent ? ' (URGENT)' : '';
        
        return $this->subject('Nouvelle tâche assignée' . $urgentText)
                    ->view('emails.task_assigned')
                    ->with([
                        'technicianName' => $this->technicianName,
                        'taskName' => $this->task->task_name,
                        'taskType' => $this->task->task_type,
                        'description' => $this->task->description,
                        'clientName' => $this->clientName,
                        'taskDate' => $this->taskDate,
                        'urgent' => $this->task->urgent,
                        'appUrl' => config('app.url'),
                    ]);
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
