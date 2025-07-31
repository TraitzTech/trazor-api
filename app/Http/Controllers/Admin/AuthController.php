<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\ActivityLogger;
use App\Helpers\AuthHelper;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Intern;
use App\Models\Specialty;
use App\Models\Supervisor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            $credentials = $request->validate([
                'email' => 'required|email',
                'password' => 'required|string|min:6',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        if (! Auth::attempt($credentials)) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        $user = Auth::user();

        if (! $user->hasRole('admin')) {
            Auth::logout();

            return response()->json([
                'message' => 'Unauthorized: Not an admin',
            ], 403);
        }

        $token = $user->createToken('admin-login')->plainTextToken;

        ActivityLogger::log($user->id, 'User logged in');

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Generate a secure random password
     */
    private function generatePassword($length = 12)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';

        // Ensure at least one character from each type
        $password .= chr(rand(65, 90)); // Uppercase
        $password .= chr(rand(97, 122)); // Lowercase
        $password .= chr(rand(48, 57)); // Number
        $password .= '!@#$%^&*'[rand(0, 7)]; // Special character

        // Fill the rest randomly
        for ($i = 4; $i < $length; $i++) {
            $password .= $characters[rand(0, strlen($characters) - 1)];
        }

        return str_shuffle($password);
    }

    /**
     * Send welcome email with credentials
     */
    private function sendCredentialsEmail($user, $password, $role, $additionalData = [])
    {
        $emailData = [
            'user' => $user,
            'password' => $password,
            'role' => ucfirst($role),
            'additionalData' => $additionalData,
        ];

        Mail::send('emails.user-credentials', $emailData, function ($message) use ($user) {
            $message->to($user->email, $user->name)
                ->subject('Welcome to the '.config('app.name').' Platform - Your Account Credentials');
        });
    }

    public function createUser(Request $request)
    {
        try {
            // Validate basic user data (removed password validation)
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:users,email',
                'role' => 'required|in:intern,supervisor,admin',
                'phone' => 'nullable|string|max:20',
                'location' => 'nullable|string|max:255',
                'bio' => 'nullable|string|max:500',

                // Intern specific fields
                'specialty_id' => 'required_if:role,intern,supervisor|exists:specialties,id',
                'institution' => 'required_if:role,intern|string|max:255',
                'hort_number' => 'required_if:role,intern|string|max:10',
                'start_date' => 'required_if:role,intern|date|after_or_equal:today',
                'end_date' => 'required_if:role,intern|date|after:start_date',

                // Admin specific fields
                'permissions' => 'required_if:role,admin|array',
                'permissions.*' => 'string|in:user_management,content_moderation,analytics,system_settings',
            ]);

            // Check authorization
            $authUser = AuthHelper::getUserFromBearerToken($request);
            if (! $authUser->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to create users',
                ], 403);
            }

            // Additional validation for intern
            if ($validated['role'] === 'intern') {
                // Check if specialty exists
                $specialty = Specialty::find($validated['specialty_id']);
                if (! $specialty) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid specialty selected',
                    ], 422);
                }

                // Validate hort number format if needed
                if (! preg_match('/^[A-Z0-9]+$/', $validated['hort_number'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Hort number must contain only letters and numbers',
                    ], 422);
                }
            }

            DB::beginTransaction();

            // Generate a secure password
            $generatedPassword = $this->generatePassword();

            // Create the user
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'location' => $validated['location'] ?? null,
                'bio' => $validated['bio'] ?? null,
                'password' => Hash::make($generatedPassword),
                'is_active' => true,
            ]);

            $additionalData = [];

            // Assign role and create role-specific record
            switch ($validated['role']) {
                case 'intern':
                    $user->assignRole('intern');

                    $intern = Intern::create([
                        'user_id' => $user->id,
                        'specialty_id' => $validated['specialty_id'],
                        'institution' => $validated['institution'],
                        'hort_number' => $validated['hort_number'],
                        'start_date' => $validated['start_date'],
                        'end_date' => $validated['end_date'],
                    ]);

                    $additionalData = [
                        'matriculation_number' => $intern->matric_number,
                        'specialty' => Specialty::find($validated['specialty_id'])->name,
                        'institution' => $validated['institution'],
                        'start_date' => $validated['start_date'],
                        'end_date' => $validated['end_date'],
                    ];

                    break;

                case 'supervisor':
                    $user->assignRole('supervisor');

                    Supervisor::create([
                        'user_id' => $user->id,
                        'specialty_id' => $validated['specialty_id'],
                    ]);

                    $additionalData = [
                        'specialty' => Specialty::find($validated['specialty_id'])->name,
                    ];

                    break;

                case 'admin':
                    $user->assignRole('admin');

                    Admin::create([
                        'user_id' => $user->id,
                        'permissions' => json_encode($validated['permissions'] ?? []),
                    ]);

                    $additionalData = [
                        'permissions' => $validated['permissions'] ?? [],
                    ];

                    break;
            }

            // Send credentials email
            try {
                $this->sendCredentialsEmail($user, $generatedPassword, $validated['role'], $additionalData);
            } catch (\Exception $emailError) {
                // Log email error but don't fail the user creation
                \Log::error('Failed to send credentials email: '.$emailError->getMessage(), [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            }

            DB::commit();

            // Log the activity
            ActivityLogger::log(Auth::id(), "Created new {$validated['role']}: {$user->name}");

            // Load relationships for response
            $user->load(['intern.specialty', 'supervisor.specialty', 'admin']);

            return response()->json([
                'success' => true,
                'message' => ucfirst($validated['role']).' created successfully. Login credentials have been sent to their email.',
                'data' => [
                    'user' => $user,
                    'matric_number' => $validated['role'] === 'intern' ? $user->intern->matric_number : null,
                ],
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error creating user: '.$e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the user',
                'error' => config('app.debug') ? $e->getMessage() : 'Failed to create user',
            ], 500);
        }
    }

    public function getSpecialties()
    {
        try {
            $specialties = Specialty::select('id', 'name', 'description')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $specialties,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch specialties',
            ], 500);
        }
    }
}
