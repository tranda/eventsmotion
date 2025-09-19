<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use App\Mail\PasswordResetMail;

class TestEmailCommand extends Command
{
    protected $signature = 'email:test {email} {--name=Test User}';
    protected $description = 'Test email configuration by sending a test password reset email';

    public function handle()
    {
        $email = $this->argument('email');
        $name = $this->option('name');
        $testToken = 'test-token-123';

        try {
            Mail::to($email)->send(new PasswordResetMail($testToken, $name));
            $this->info("Test email sent successfully to {$email}");
            return 0;
        } catch (\Exception $e) {
            $this->error("Failed to send email: " . $e->getMessage());
            return 1;
        }
    }
}