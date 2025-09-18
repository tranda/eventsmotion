<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public $token;
    public $userName;

    public function __construct($token, $userName)
    {
        $this->token = $token;
        $this->userName = $userName;
    }

    public function build()
    {
        $resetUrl = env('APP_URL') . '/reset-password?token=' . $this->token;

        return $this->subject('Reset Your Password - EuroCup Events')
                    ->view('emails.password-reset')
                    ->with([
                        'resetUrl' => $resetUrl,
                        'userName' => $this->userName,
                        'token' => $this->token
                    ]);
    }
}