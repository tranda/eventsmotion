<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Mail\PasswordResetMail;

class PasswordResetController extends BaseController
{
    public function forgotPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return $this->sendError('User not found', [], 404);
        }

        // Generate a unique token
        $token = Str::random(64);

        // Delete any existing tokens for this email
        DB::table('password_resets')->where('email', $request->email)->delete();

        // Insert new token
        DB::table('password_resets')->insert([
            'email' => $request->email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now()
        ]);

        // Send password reset email
        try {
            Mail::to($request->email)->send(new PasswordResetMail($token, $user->name));

            return $this->sendResponse([], 'Password reset link has been sent to your email address.');
        } catch (\Exception $e) {
            // Delete the token if email fails to send
            DB::table('password_resets')->where('email', $request->email)->delete();

            return $this->sendError('Failed to send email. Please try again later.', [], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error', $validator->errors(), 422);
        }

        // Find the password reset record
        $passwordReset = DB::table('password_resets')
            ->where('email', $request->email)
            ->first();

        if (!$passwordReset) {
            return $this->sendError('Invalid reset token', [], 400);
        }

        // Check if token matches
        if (!Hash::check($request->token, $passwordReset->token)) {
            return $this->sendError('Invalid reset token', [], 400);
        }

        // Check if token is not expired (60 minutes)
        $tokenCreated = Carbon::parse($passwordReset->created_at);
        if ($tokenCreated->addMinutes(60)->isPast()) {
            // Delete expired token
            DB::table('password_resets')->where('email', $request->email)->delete();
            return $this->sendError('Reset token has expired. Please request a new one.', [], 400);
        }

        // Update user password
        $user = User::where('email', $request->email)->first();
        $user->password = Hash::make($request->password);
        $user->save();

        // Delete the used token
        DB::table('password_resets')->where('email', $request->email)->delete();

        return $this->sendResponse([], 'Password has been successfully reset.');
    }
}