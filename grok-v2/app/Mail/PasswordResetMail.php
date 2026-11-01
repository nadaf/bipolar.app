<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Recuperacao de senha: texto neutro (sem dado de saude). */
class PasswordResetMail extends Mailable
{
    use Queueable;

    public function __construct(public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Redefinir sua senha');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.password-reset');
    }
}
