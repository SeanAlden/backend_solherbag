<?php

namespace App\Mail;

use App\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;

class AdminResponseMail extends Mailable
{
    use Queueable, SerializesModels;

    public $contact;

    public function __construct(Contact $contact)
    {
        $this->contact = $contact;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('solherbag@gmail.com', 'Solher Bag Support'),
            subject: 'Response to Your Inquiry - Solher Bag',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact_response', // Kita akan buat file blade-nya di bawah
        );
    }
}
