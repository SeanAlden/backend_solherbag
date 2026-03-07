<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;

class WelcomeSubscriberMail extends Mailable
{
    use Queueable, SerializesModels;

    public $email;

    public function __construct($email)
    {
        $this->email = $email;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('solherbag@gmail.com', 'Solher Bag Newsletter'),
            subject: 'Welcome to Solher Bag Exclusives!',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.newsletter_welcome',
        );
    }
}
