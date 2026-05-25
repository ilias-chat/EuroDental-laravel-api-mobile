<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Models\ProposedTask;

class TaskProposedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $proposedTask;
    public $proposerName;
    public $clientName;

    /**
     * Create a new message instance.
     */
    public function __construct(ProposedTask $proposedTask, $proposerName, $clientName = null)
    {
        $this->proposedTask = $proposedTask;
        $this->proposerName = $proposerName;
        $this->clientName = $clientName;
    }

    public function build()
    {
        $urgentText = $this->proposedTask->urgent ? ' (URGENT)' : '';
        
        return $this->subject('Nouvelle proposition de tâche' . $urgentText)
                    ->view('emails.task_proposed')
                    ->with([
                        'proposerName' => $this->proposerName,
                        'taskName' => $this->proposedTask->task_name,
                        'taskType' => $this->proposedTask->task_type,
                        'description' => $this->proposedTask->description,
                        'clientName' => $this->clientName,
                        'urgent' => $this->proposedTask->urgent,
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

