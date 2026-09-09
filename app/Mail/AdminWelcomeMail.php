<?php

namespace App\Mail;

use App\Models\AdminUser;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * GFT-127 — sent once, when a Super Admin / Admin creates a Manager, Moderator or Admin
 * panel account (AdminUserController::store). Carries the plaintext password, so this is
 * the only place it ever leaves the request that created it — AdminUser hashes it on save
 * and nothing else can recover it afterward. Locally MAIL_MAILER=log, so this lands in
 * storage/logs/laravel.log same as AdminOtpMail.
 */
class AdminWelcomeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public AdminUser $admin,
        public string $password,
        public string $roleName,
        public string $panelUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Guftagu admin panel account',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.admin-welcome',
            with: [
                'name'     => $this->admin->name,
                'email'    => $this->admin->email,
                'password' => $this->password,
                'roleName' => $this->roleName,
                'panelUrl' => $this->panelUrl,
            ],
        );
    }
}
