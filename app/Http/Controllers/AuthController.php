<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Public Registration (Disabled - Admin only)
     */
    public function register(Request $request)
    {
        return response()->json([
            'message' => 'Public registration is disabled. Only administrators can add new users.',
        ], 403);
    }

    /**
     * Authenticate user & issue token
     */
    public function login(Request $request)
    {
        $fields = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($fields)) {
            return response()->json([
                'message' => 'Invalid credentials provided.',
            ], 401);
        }

        $user = User::where('email', $fields['email'])->firstOrFail();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ], 200);
    }

    /**
     * Revoke current token / Logout
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ], 200);
    }

    /**
     * Fetch current user details
     */
    public function me(Request $request)
    {
        return response()->json([
            'user' => $request->user(),
        ], 200);
    }

    /**
     * Update Profile Info (name, email)
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $fields = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
        ]);

        $user->update($fields);

        return response()->json([
            'message' => 'Profile updated successfully!',
            'user' => $user->fresh(),
        ], 200);
    }

    /**
     * Change Password
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (!Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'message' => 'Current password does not match our records.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->input('password')),
        ]);

        return response()->json([
            'message' => 'Password changed successfully!',
        ], 200);
    }

    /**
     * List all users (Admin only)
     */
    public function getAllUsers(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        $users = User::select('id', 'name', 'email', 'role', 'created_at')
            ->withCount('processedFiles')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'users' => $users,
        ], 200);
    }

    /**
     * Update a user's role (Admin only)
     */
    public function updateUserRole(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        $request->validate([
            'role' => 'required|string|in:admin,user,authority',
        ]);

        $targetUser = User::findOrFail($id);

        $targetUser->update([
            'role' => $request->input('role'),
        ]);

        return response()->json([
            'message' => "Role for {$targetUser->name} updated to '{$targetUser->role}' successfully!",
            'user' => $targetUser,
        ], 200);
    }

    /**
     * Create a new user (Admin only)
     */
    public function createUser(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        $fields = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => ['required', 'string', 'min:6'],
            'role' => 'required|string|in:admin,user,authority',
        ]);

        $newUser = User::create([
            'name' => trim($fields['name']),
            'email' => trim($fields['email']),
            'password' => Hash::make($fields['password']),
            'role' => $fields['role'] ?? 'user',
        ]);

        return response()->json([
            'message' => "User '{$newUser->name}' created successfully!",
            'user' => $newUser,
        ], 201);
    }

    /**
     * Delete a user (Admin only)
     */
    public function deleteUser(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized action.'], 403);
        }

        if ((int)$id === (int)$user->id) {
            return response()->json(['message' => 'You cannot delete your own admin account.'], 422);
        }

        $targetUser = User::findOrFail($id);
        $targetUser->delete();

        return response()->json([
            'message' => "User '{$targetUser->name}' removed successfully!",
        ], 200);
    }
}
