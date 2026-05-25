<?php

namespace App\Mail;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewTicketMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public string $creatorName
    ) {
        $this->ticket->load('user');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nouveau ticket : ' . \Str::limit($this->ticket->subject, 50),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new_ticket',
        );
    }
}
