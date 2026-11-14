<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $userName;
    public string $userEmail;
    public string $resetUrl;

    public function __construct(string $userName, string $userEmail, string $resetUrl)
    {
        $this->userName = $userName;
        $this->userEmail = $userEmail;
        $this->resetUrl = $resetUrl;
    }

    public function build()
    {
        return $this->subject('Reset Your Password - 10X Agency CRM')
            ->view('emails.password-reset-link');
    }
}
