<?php

namespace App\Mail;

use App\Models\AdminUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * GFT-003 — the login OTP. Locally MAIL_MAILER=log, so the code lands in
 * storage/logs/laravel.log, which is how the flow is manually tested without SMTP.
 */
class AdminOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AdminUser $admin,
        public string $otp,
        public int $ttlMinutes,
        public string $purpose = 'login',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->purpose === 'reauth'
                ? 'Guftagu admin — confirm a high-risk action'
                : 'Guftagu admin — your login code',
        );
    }

    public function content(): Content
    {
        // markdown:, not view: — the template uses the x-mail::* components, which are
        // only registered for markdown mailables.
        return new Content(
            markdown: 'mail.admin-otp',
            with: [
                'name'    => $this->admin->name,
                'otp'     => $this->otp,
                'ttl'     => $this->ttlMinutes,
                'purpose' => $this->purpose,
            ],
        );
    }
}
