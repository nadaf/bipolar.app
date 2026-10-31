<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Convite de clinica: texto neutro (sem dado de saude, sem nome de paciente). */
class ClinicInviteMail extends Mailable
{
    use Queueable;

    public function __construct(public string $clinic, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Convite para a equipe no Bipolar');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.clinic-invite');
    }
}
