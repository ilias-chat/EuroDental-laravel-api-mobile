<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SendInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public $order;
    public $pdfPath;

    public function __construct($order, $pdfPath)
    {
        $this->order = $order;
        $this->pdfPath = $pdfPath;
    }

    public function build()
    {
        return $this->subject('Votre facture EuroDental')
                    ->view('emails.invoice_sent')
                    ->attach($this->pdfPath, [
                        'as' => 'facture.pdf',
                        'mime' => 'application/pdf'
                    ]);
    }
}

