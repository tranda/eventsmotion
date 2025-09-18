<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\PasswordResetMail;
use Carbon\Carbon;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => Hash::make('oldpassword'),
        ]);
    }

    public function test_forgot_password_sends_email_for_valid_user()
    {
        Mail::fake();

        $response = $this->postJson('/api/forgot-password', [
            'email' => $this->user->email,
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Password reset link has been sent to your email address.',
                ]);

        // Assert a password reset record was created
        $this->assertDatabaseHas('password_resets', [
            'email' => $this->user->email,
        ]);

        // Assert an email was sent
        Mail::assertSent(PasswordResetMail::class, function ($mail) {
            return $mail->hasTo($this->user->email);
        });
    }

    public function test_forgot_password_fails_for_invalid_email()
    {
        $response = $this->postJson('/api/forgot-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
    }

    public function test_forgot_password_requires_email()
    {
        $response = $this->postJson('/api/forgot-password', []);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
    }

    public function test_forgot_password_requires_valid_email_format()
    {
        $response = $this->postJson('/api/forgot-password', [
            'email' => 'invalid-email',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
    }

    public function test_reset_password_with_valid_token()
    {
        // Create a password reset token
        $token = 'test-reset-token';
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now(),
        ]);

        $newPassword = 'newpassword123';

        $response = $this->postJson('/api/reset-password', [
            'email' => $this->user->email,
            'token' => $token,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Password has been successfully reset.',
                ]);

        // Assert the password was updated
        $this->user->refresh();
        $this->assertTrue(Hash::check($newPassword, $this->user->password));

        // Assert the token was deleted
        $this->assertDatabaseMissing('password_resets', [
            'email' => $this->user->email,
        ]);
    }

    public function test_reset_password_fails_with_invalid_token()
    {
        // Create a password reset token
        $validToken = 'valid-token';
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make($validToken),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email' => $this->user->email,
            'token' => 'invalid-token',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(400)
                ->assertJson([
                    'success' => false,
                    'message' => 'Invalid reset token',
                ]);
    }

    public function test_reset_password_fails_with_expired_token()
    {
        // Create an expired password reset token (older than 60 minutes)
        $token = 'expired-token';
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now()->subMinutes(61), // 61 minutes ago
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email' => $this->user->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(400)
                ->assertJson([
                    'success' => false,
                    'message' => 'Reset token has expired. Please request a new one.',
                ]);

        // Assert the expired token was deleted
        $this->assertDatabaseMissing('password_resets', [
            'email' => $this->user->email,
        ]);
    }

    public function test_reset_password_requires_password_confirmation()
    {
        $token = 'test-token';
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email' => $this->user->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'differentpassword',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_requires_minimum_password_length()
    {
        $token = 'test-token';
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email' => $this->user->email,
            'token' => $token,
            'password' => '123',
            'password_confirmation' => '123',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['password']);
    }

    public function test_reset_password_fails_without_existing_token()
    {
        $response = $this->postJson('/api/reset-password', [
            'email' => $this->user->email,
            'token' => 'nonexistent-token',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(400)
                ->assertJson([
                    'success' => false,
                    'message' => 'Invalid reset token',
                ]);
    }

    public function test_forgot_password_replaces_existing_token()
    {
        Mail::fake();

        // Create an existing token
        $oldToken = 'old-token';
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make($oldToken),
            'created_at' => Carbon::now()->subMinutes(30),
        ]);

        // Request a new password reset
        $response = $this->postJson('/api/forgot-password', [
            'email' => $this->user->email,
        ]);

        $response->assertStatus(200);

        // Assert only one record exists for this email
        $tokens = DB::table('password_resets')
            ->where('email', $this->user->email)
            ->get();

        $this->assertCount(1, $tokens);

        // Assert the token was updated (created_at should be recent)
        $this->assertTrue(Carbon::parse($tokens->first()->created_at)->greaterThan(Carbon::now()->subMinute()));
    }
}