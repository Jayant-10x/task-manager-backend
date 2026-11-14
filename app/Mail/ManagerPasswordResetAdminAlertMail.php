<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ManagerPasswordResetAdminAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $managerName;
    public string $managerEmail;
    public string $requestedAt;
    public ?string $ipAddress;
    public ?string $resetActionUrl;

    public function __construct(string $managerName, string $managerEmail, string $requestedAt, ?string $ipAddress = null, ?string $resetActionUrl = null)
    {
        $this->managerName = $managerName;
        $this->managerEmail = $managerEmail;
        $this->requestedAt = $requestedAt;
        $this->ipAddress = $ipAddress;
        $this->resetActionUrl = $resetActionUrl;
    }

    public function build()
    {
        return $this->subject('Alert: Manager Password Reset Requested - 10X Agency CRM')
            ->view('emails.manager-reset-admin-alert');
    }
}
