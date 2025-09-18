<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Session;

class AuthController extends BaseController
{
    public function login()
    {
        return view('auth.login');
    }
	
	public function authenticate(Request $request)
    {
        $credentials = $request->only('username', 'password');

        if (Auth::attempt($credentials)) {
            $user = User::where('username', $request->username)->first();
            if ($user && $this->approved($user))
            {
                $token = $user->createToken("API TOKEN")->plainTextToken;

                // return response()->json([
                //     'status' => true,
                //     'message' => 'User Logged In Successfully',
                //     'token' => $user->createToken("API TOKEN")->plainTextToken
                // ], 200);
                $data = [
                    'user' => $user, // Auth()->getUser(),
                    'token' => $token
                ];

                return parent::sendResponse($data, 'User logged in');
            }
            else
            {
                return parent::sendError("User not valid");
            }
        }

		return parent::sendError("User not found");
    }

    public function logout(Request $request)
    {
        try {
            // Check if user is authenticated
            if (!$request->user()) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }

            // Revoke the user's current token
            $token = $request->user()->currentAccessToken();
            if ($token) {
                $token->delete();
            }

            return response()->json(['message' => 'Logged out successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Logout failed: ' . $e->getMessage()], 500);
        }
    }

    protected function approved(User $user)
    {
        $result = true; // $user->access_level > 1 || $user->id < 5;

        return $result;
    }
}
