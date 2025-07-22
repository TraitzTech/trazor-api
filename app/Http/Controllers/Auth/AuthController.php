<?php

namespace App\Http\Controllers\Auth;

use App\Helpers\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * Authenticate and login the user
     */
    public function login(Request $request)
    {
        // Validate input
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid input',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Attempt login (checks email & password)
        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Incorrect email or password.',
            ], 401);
        }

        // Get the authenticated user
        $user = Auth::user();

        // Check if account is active
        if (! $user->is_active) {
            Auth::logout(); // optional, for safety

            return response()->json([
                'message' => 'Your account is not active. Please contact the system admin.',
            ], 403);
        }

        // Issue token and log activity
        $token = $user->createToken('api-token')->plainTextToken;

        ActivityLogger::log($user->id, 'User logged in');

        // Fetch recent activities (latest 10)
        $recentActivities = $user->activities()
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($activity) {
                return [
                    'action' => $activity->action,
                    'time' => \Carbon\Carbon::parse($activity->created_at)->format('F j, Y g:i A'),
                ];
            });

        return response()->json([
            'token' => $token,
            'user' => $user,
            'role' => $user->getRoleNames()->first(),
            'recent_activities' => $recentActivities,
        ]);
    }

    /**
     * Register new user (optional)
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Assign default role if needed
        $user->assignRole('intern'); // or 'user', 'intern', etc.

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user,
            'role' => $user->getRoleNames()->first(),
        ]);
    }

    /**
     * Get authenticated user details
     */
    public function getAuthUser(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => $user,
            'role' => $user->getRoleNames()->first(),
        ]);
    }

    /**
     * Logout and revoke token
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
