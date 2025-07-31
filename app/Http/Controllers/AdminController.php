<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdminRequest;
use App\Http\Requests\UpdateAdminRequest;
use App\Models\Admin;
use App\Models\Intern;
use App\Models\Supervisor;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function getAllInterns(Request $request): JsonResponse
    {
        $interns = Intern::with(['user', 'specialty'])
            ->get()
            ->map(function ($intern) {
                return [
                    'id' => $intern->id,
                    'user_id' => $intern->user->id,
                    'name' => $intern->user->name,
                    'email' => $intern->user->email,
                    'status' => $intern->user->is_active ? 'active' : 'inactive',
                    'joinDate' => $intern->user->created_at->toDateString(),
                    'avatar' => $intern->user->avatar ?? '/placeholder-avatar.jpg',
                    'location' => $intern->user->location ?? 'Unknown',
                    'institution' => $intern->institution ?? 'N/A',
                    'matricNumber' => $intern->matric_number ?? 'N/A',
                    'hortNumber' => $intern->hort_number,
                    'startDate' => $intern->start_date,
                    'endDate' => $intern->end_date,
                    'specialty' => optional($intern->specialty)->name ?? 'Unassigned',
                    'supervisors' => $intern->specialty ? $intern->specialty->supervisors->map(function ($supervisor) {
                        return [
                            'id' => $supervisor->id,
                            'name' => $supervisor->user->name,
                            'email' => $supervisor->user->email,
                        ];
                    })->toArray() : [],
                    'role' => 'intern',
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $interns,
        ]);
    }

    public function getAllSupervisors(Request $request): JsonResponse
    {
        $supervisors = Supervisor::with(['user', 'specialty'])
            ->get()
            ->map(function ($supervisor) {
                return [
                    'id' => $supervisor->id,
                    'user_id' => $supervisor->user->id,
                    'name' => $supervisor->user->name,
                    'email' => $supervisor->user->email,
                    'status' => $supervisor->user->is_active ? 'active' : 'inactive',
                    'joinDate' => $supervisor->user->created_at->toDateString(),
                    'avatar' => $supervisor->user->avatar ?? '/placeholder-avatar.jpg',
                    'location' => $supervisor->user->location ?? 'Unknown',
                    'department' => $supervisor->department ?? 'N/A',
                    'position' => $supervisor->position ?? 'N/A',
                    'specialty' => optional($supervisor->specialty)->name ?? 'Unassigned',
                    'internCount' => $supervisor->specialty ? $supervisor->specialty->interns->count() : 0,
                    'role' => 'supervisor',
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $supervisors,
        ]);
    }

    public function getAllAdmins(Request $request): JsonResponse
    {
        $admins = Admin::with(['user'])
            ->get()
            ->map(function ($admin) {
                return [
                    'id' => $admin->id,
                    'user_id' => $admin->user->id,
                    'name' => $admin->user->name,
                    'email' => $admin->user->email,
                    'status' => $admin->user->is_active ? 'active' : 'inactive',
                    'joinDate' => $admin->user->created_at->toDateString(),
                    'avatar' => $admin->user->avatar ?? '/placeholder-avatar.jpg',
                    'location' => $admin->user->location ?? 'Unknown',
                    'department' => $admin->department ?? 'Administration',
                    'permissions' => $admin->permissions ?? [],
                    'role' => 'admin',
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $admins,
        ]);
    }

    // Alternative method: Get all users in one API call
    public function getAllUsers(Request $request): JsonResponse
    {
        $users = collect();

        // Get interns
        $interns = Intern::with(['user', 'specialty'])->has('user')
            ->get()
            ->map(function ($intern) {
                return [
                    'id' => $intern->id,
                    'user_id' => $intern->user->id,
                    'name' => $intern->user->name,
                    'email' => $intern->user->email,
                    'status' => $intern->user->is_active ? 'active' : 'inactive',
                    'joinDate' => $intern->user->created_at->toDateString(),
                    'avatar' => $intern->user->avatar ?? '/placeholder-avatar.jpg',
                    'location' => $intern->user->location ?? 'Unknown',
                    'institution' => $intern->institution ?? 'N/A',
                    'matricNumber' => $intern->matric_number ?? 'N/A',
                    'hort_number' => $intern->hort_number,
                    'specialty' => optional($intern->specialty)->name ?? 'Unassigned',
                    'role' => 'intern',
                ];
            });

        // Get supervisors
        $supervisors = Supervisor::with(['user', 'specialty'])->has('user')
            ->get()
            ->map(function ($supervisor) {
                return [
                    'id' => $supervisor->id,
                    'user_id' => $supervisor->user->id,
                    'name' => $supervisor->user->name,
                    'email' => $supervisor->user->email,
                    'status' => $supervisor->user->is_active ? 'active' : 'inactive',
                    'joinDate' => $supervisor->user->created_at->toDateString(),
                    'avatar' => $supervisor->user->avatar ?? '/placeholder-avatar.jpg',
                    'location' => $supervisor->user->location ?? 'Unknown',
                    'department' => $supervisor->department ?? 'N/A',
                    'specialty' => optional($supervisor->specialty)->name ?? 'Unassigned',
                    'role' => 'supervisor',
                ];
            });

        // Get admins
        $admins = Admin::with(['user'])
            ->get()
            ->map(function ($admin) {
                return [
                    'id' => $admin->id,
                    'user_id' => $admin->user->id,
                    'name' => $admin->user->name,
                    'email' => $admin->user->email,
                    'status' => $admin->user->is_active ? 'active' : 'inactive',
                    'joinDate' => $admin->user->created_at->toDateString(),
                    'avatar' => $admin->user->avatar ?? '/placeholder-avatar.jpg',
                    'location' => $admin->user->location ?? 'Unknown',
                    'department' => $admin->department ?? 'Administration',
                    'role' => 'admin',
                ];
            });

        $users = $users->concat($interns)->concat($supervisors)->concat($admins);

        return response()->json([
            'success' => true,
            'data' => $users->toArray(),
        ]);
    }

    public function showUser($id)
    {
        $user = User::with('intern', 'supervisor', 'admin', 'settings')->findOrFail($id);

        $roleData = $user->intern ?? $user->supervisor ?? $user->admin;

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->is_active ? 'active' : 'inactive',
                'joinDate' => $user->created_at->toDateString(),
                'avatar' => $user->avatar,
                'location' => $user->location,
                'phone' => $user->phone,
                'bio' => $user->bio,
                'role' => $user->intern ? 'intern' : ($user->supervisor ? 'supervisor' : 'admin'),
                'institution' => $user->intern->institution ?? null,
                'specialty' => $user->intern ? $user->intern->specialty->name : ($user->supervisor ? $user->supervisor->specialty->name : null),
                // Settings
                'settings' => [
                    'email_notifications' => $user->settings->email_notifications ?? true,
                    'profile_public' => $user->settings->profile_public ?? true,
                    'two_factor_auth' => $user->settings->two_factor_auth ?? false,
                ],

                // Activities
                'activities' => $user->activities
                    ->sortByDesc('created_at')      // Sort by latest
                    ->take(10)                      // Get only the most recent 10
                    ->map(function ($activity) {
                        return [
                            'action' => $activity->action,
                            'time' => Carbon::parse($activity->created_at)->format('F j, Y g:i A'),
                        ];
                    })
                    ->values()
                    ->toArray(),
            ],
        ]);

    }

    public function toggleUserStatus(Request $request, $id): JsonResponse
    {
        try {
            // Find the user record first
            $user = User::find($id);

            if (! $user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Toggle the status
            $user->is_active = ! $user->is_active;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'User status updated successfully',
                'data' => [
                    'id' => $user->id,
                    'status' => $user->is_active ? 'active' : 'inactive',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Common validation for all users
        $commonRules = [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'role' => 'required|in:intern,supervisor,admin,super_admin',
            'status' => 'required|in:active,inactive,pending,suspended',
            'phone' => 'nullable|string|max:20',
            'location' => 'nullable|string|max:255',
            'bio' => 'nullable|string',
            'avatar' => 'nullable|string',
        ];

        // Role-specific validation
        $roleSpecificRules = [];
        $role = $request->input('role', $user->role);

        if ($role === 'intern') {
            $roleSpecificRules = [
                'institution' => 'required|string|max:255',
                'specialty' => 'required|exists:specialties,id',
                'hort_number' => 'nullable|string|max:10',
            ];
        } elseif ($role === 'supervisor') {
            $roleSpecificRules = [
                'specialty' => 'required|exists:specialties,id',
            ];
        }

        // FIXED: Using the validator() helper function
        $validator = validator($request->all(), array_merge($commonRules, $roleSpecificRules));

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Start transaction to ensure data consistency
        DB::beginTransaction();

        try {
            // Update user fields
            $userFields = $validator->validated();
            $user->update($userFields);

            // Update role-specific fields
            if ($role === 'intern') {
                $internData = $validator->validated();

                if ($user->intern) {
                    $user->intern()->update([
                        'specialty_id' => $internData['specialty'],
                        'institution' => $internData['institution'],
                        'hort_number' => $internData['hort_number'],
                    ]);
                } else {
                    $user->intern()->create([
                        'specialty_id' => $internData['specialty'],
                        'institution' => $internData['institution'],
                        'hort_number' => $internData['hort_number'],
                    ]);
                    $user->supervisor()->delete();
                    $user->admin()->delete();
                }
            } elseif ($role === 'supervisor') {
                $supervisorData = $validator->validated();

                if ($user->supervisor) {
                    $user->supervisor()->update([
                        'specialty_id' => $supervisorData['specialty'],
                    ]);
                } else {
                    $user->supervisor()->create([
                        'specialty_id' => $supervisorData['specialty'],
                    ]);
                    $user->intern()->delete();
                    $user->admin()->delete();
                }
            } elseif (in_array($role, ['admin', 'super_admin'])) {
                $user->intern()->delete();
                $user->supervisor()->delete();

                if (! $user->admin) {
                    $user->admin()->create([
                        'permissions' => json_encode([]),
                    ]);
                }
            }

            // Update role and status
            $user->syncRoles([$role]);
            $user->update(['is_active' => $request->status === 'active']);

            DB::commit();

            return response()->json([
                'message' => 'User updated successfully',
                'user' => $user->fresh()->load(['intern', 'supervisor', 'admin']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error updating user',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAdminRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Admin $admin)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Admin $admin)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAdminRequest $request, Admin $admin)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Admin $admin)
    {
        //
    }

    public function getUserActivities($userId)
    {
        $user = User::findOrFail($userId);
        $activities = $user->activities()->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $activities,
        ]);
    }
}
