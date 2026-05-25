<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;
    
    public $user, $password;

    public function __construct($user, $password)
    {
        $this->user = $user;
        $this->password = $password;
    }
    
    public function build()
    {
        return $this->subject('Your new password')
            ->markdown('emails.password_reset');
    }
    
}
