<?php

namespace App\Mail;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;
use Illuminate\Mail\Mailables\Envelope;

class NewProductAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public $product;

    public function __construct(Product $product)
    {
        $this->product = $product;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('solherbag@gmail.com', 'Solher Bag'),
            subject: 'New Arrival: ' . $this->product->name . ' is Here!',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.new_product_alert');
    }
}
