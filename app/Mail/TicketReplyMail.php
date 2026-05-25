<?php

namespace App\Mail;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TicketReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Ticket $ticket,
        public TicketReply $reply,
        public string $authorName
    ) {
        $this->ticket->load('user');
        $this->reply->load('user');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nouvelle réponse sur le ticket : ' . \Str::limit($this->ticket->subject, 50),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket_reply',
        );
    }
}
