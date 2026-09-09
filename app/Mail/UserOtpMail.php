<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Epic D.1a — the app's email OTP (sign-in/registration or password reset). Locally
 * MAIL_MAILER=log, so the code lands in storage/logs/laravel.log.
 */
class UserOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public int $ttlMinutes,
        public string $purpose = 'auth',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose === 'reset_password'
                ? 'Guftagu — reset your password'
                : 'Guftagu — your verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.user-otp',
            with: [
                'otp'     => $this->otp,
                'ttl'     => $this->ttlMinutes,
                'purpose' => $this->purpose,
            ],
        );
    }
}
