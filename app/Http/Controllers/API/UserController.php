<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class UserController extends BaseController
{
	//
	public function showRegistrationForm()
	{
		return view('auth.register');
	}

	public function createUser(Request $request)
	{
		$validator = Validator::make($request->all(), [
			'name' => 'required|string|max:255',
			'username' =>' required|string|max:255',
			'email' => 'required|string|email|max:255',
			'password' => 'required|string|min:8|confirmed',
			'club_id' => 'required',
			'event_id' => 'required',
			'access_level' => 'required'
		]);

		if ($validator->fails()) {
			return parent::sendError('Failed to register', $validator->errors(), 201);
		}

		$user = new User;
		$user->name = $request->name;
		$user->username = $request->username;
		$user->email = $request->email;
		$user->password = bcrypt($request->password);
		$user->club_id = $request->club_id;
		$user->event_id = $request->event_id;
		$user->access_level = $request->access_level;
		$user->save();

		return parent::sendResponse($user, 'Successfull registration');
	}

	public function updateUser(Request $request, $id)
	{
		$validator = Validator::make($request->all(), [
			'name' => 'string|max:255',
			'email' => 'string|email|max:255|unique:users'
		]);

		if ($validator->fails()) {
			return parent::sendError('Failed to update', $validator->errors(), 201);
		}

		$user = User::findOrFail($id);
		if ($request->has('name')) {
			$user->name = $request->name;
		}
		if ($request->has('username')) {
			$user->name = $request->username;
		}
		if ($request->has('email')) {
			$user->email = $request->email;
		}
		if ($request->has('club_id')) {
			$user->club_id = $request->club_id;
		}
		if ($request->has('event_id')) {
			$user->event_id = $request->event_id;
		}
		if ($request->has('access_level')) {
			$user->access_level = $request->access_level;
		}
		$user->save();

		return parent::sendResponse($user, 'Successfull update');
	}

	public function updatePassword(Request $request, $id)
	{
		$validator = Validator::make($request->all(), [
			'password' => 'required|string|min:8|confirmed',
		]);

		if ($validator->fails()) {
			return parent::sendError('Failed to update', $validator->errors(), 201);
		}

		$user = User::findOrFail($id);
		$user->password = bcrypt($request->password);
		
		$user->save();

		return parent::sendResponse($user, 'Successfull update');
	}

	public function getUsers()
	{
		$users = User::all();
		return response()->json($users);
	}

	public function getUser($id)
	{
		$user = User::findOrFail($id);
		return response()->json($user);
	}

	public function deleteUser($id)
	{
		$user = User::findOrFail($id);
		$user->delete();
		return response()->json($user);
	}

	public function getClubId(Request $request)
	{
		$user = Auth::user(); // $request->user(); // Retrieve the authenticated user

		if ($user) {
			$clubId = $user->club->id;
			return response()->json(['club_id' => $clubId]);
		}

		// Handle the case where user is not authenticated or does not have a club
		return response()->json(['error' => 'User not found or does not have a club'], 404);
	}
}
