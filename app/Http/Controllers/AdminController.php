<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdminRequest;
use App\Http\Requests\UpdateAdminRequest;
use App\Models\Admin;
use App\Models\Intern;
use App\Models\Logbook;
use App\Models\Specialty;
use App\Models\Supervisor;
use App\Models\Task;
use App\Models\User;
use App\Models\UserActivity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * @tags Admin Management
 */
class AdminController extends Controller
{
    /**
     * Get Dashboard Data
     *
     * Retrieve comprehensive dashboard analytics and statistics for administrators.
     * Includes user metrics, recent activities, pending items, task overview,
     * logbook statistics, and specialty breakdowns.
     *
     * @queryParam period string Time period for analytics. Accepts: 24h, 7d, 30d, 90d. Example: 7d
     *
     * @response 200 {
     *   "status": "success",
     *   "message": "Dashboard data retrieved successfully",
     *   "data": {
     *     "metrics": {
     *       "total_users": {"value": 150, "change": "+12%", "trend": "up"},
     *       "active_internships": {"value": 45, "change": "+8%", "trend": "up"},
     *       "total_applications": {"value": 200, "change": "+5%", "trend": "up"},
     *       "successful_matches": {"value": 120, "change": "+15%", "trend": "up"}
     *     },
     *     "recent_activities": [],
     *     "pending_items": [],
     *     "system_stats": {},
     *     "task_overview": {},
     *     "logbook_stats": {},
     *     "specialty_stats": [],
     *     "period": "7d",
     *     "last_updated": "2026-01-17T10:30:00Z"
     *   }
     * }
     * @response 500 {"status": "error", "message": "Failed to retrieve dashboard data"}
     */
    public function getDashboardData(Request $request)
    {
        try {
            // Get date range (default to last 7 days)
            $period = $request->get('period', '7d');
            $startDate = $this->getStartDateFromPeriod($period);

            // Get all the dashboard metrics
            $metrics = $this->getDashboardMetrics();
            $recentActivities = $this->getRecentActivities(10);
            $pendingItems = $this->getPendingItems();
            $systemStats = $this->getSystemStats();
            $taskOverview = $this->getTaskOverview();
            $logbookStats = $this->getLogbookStats();
            $specialtyStats = $this->getSpecialtyStats();

            return response()->json([
                'status' => 'success',
                'message' => 'Dashboard data retrieved successfully',
                'data' => [
                    'metrics' => $metrics,
                    'recent_activities' => $recentActivities,
                    'pending_items' => $pendingItems,
                    'system_stats' => $systemStats,
                    'task_overview' => $taskOverview,
                    'logbook_stats' => $logbookStats,
                    'specialty_stats' => $specialtyStats,
                    'period' => $period,
                    'last_updated' => now()->toISOString(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve dashboard data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get main dashboard metrics
     */
    private function getDashboardMetrics()
    {
        $totalUsers = User::count();
        $previousWeekUsers = User::where('created_at', '<=', now()->subWeek())->count();
        $userGrowth = $previousWeekUsers > 0 ?
            round((($totalUsers - $previousWeekUsers) / $previousWeekUsers) * 100, 1) : 0;

        $activeInternships = Intern::whereNull('end_date')
            ->orWhere('end_date', '>', now())
            ->count();

        $totalApplications = Intern::count(); // Assuming each intern record is an application
        $previousWeekApplications = Intern::where('created_at', '<=', now()->subWeek())->count();
        $applicationGrowth = $previousWeekApplications > 0 ?
            round((($totalApplications - $previousWeekApplications) / $previousWeekApplications) * 100, 1) : 0;

        $successfulMatches = Intern::whereNotNull('start_date')
            ->where('start_date', '<=', now())
            ->count();

        return [
            'total_users' => [
                'value' => $totalUsers,
                'change' => ($userGrowth >= 0 ? '+' : '').$userGrowth.'%',
                'trend' => $userGrowth >= 0 ? 'up' : 'down',
            ],
            'active_internships' => [
                'value' => $activeInternships,
                'change' => '+8%', // You can calculate this based on your needs
                'trend' => 'up',
            ],
            'total_applications' => [
                'value' => $totalApplications,
                'change' => ($applicationGrowth >= 0 ? '+' : '').$applicationGrowth.'%',
                'trend' => $applicationGrowth >= 0 ? 'up' : 'down',
            ],
            'successful_matches' => [
                'value' => $successfulMatches,
                'change' => '+15%', // Calculate based on your logic
                'trend' => 'up',
            ],
        ];
    }

    /**
     * Get recent activities across the platform
     */
    private function getRecentActivities($limit = 10)
    {
        $activities = collect();

        // Get recent user registrations
        $recentUsers = User::with(['intern', 'supervisor'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($user) {
                $userType = $user->intern ? 'intern' : ($user->supervisor ? 'supervisor' : 'user');

                return [
                    'id' => $user->id,
                    'type' => 'registration',
                    'user' => $user->name,
                    'action' => "registered as new {$userType}",
                    'time' => $user->created_at->diffForHumans(),
                    'timestamp' => $user->created_at,
                    'icon' => 'user-plus',
                    'color' => 'blue',
                ];
            });

        // Get recent task assignments
        $recentTasks = Task::with(['assigner', 'specialty'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'type' => 'task_assigned',
                    'user' => $task->assigner->name ?? 'System',
                    'action' => "assigned task: {$task->title}",
                    'time' => $task->created_at->diffForHumans(),
                    'timestamp' => $task->created_at,
                    'icon' => 'clipboard-list',
                    'color' => 'green',
                ];
            });

        // Get recent logbook submissions
        $recentLogbooks = Logbook::with(['intern.user'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($logbook) {
                return [
                    'id' => $logbook->id,
                    'type' => 'logbook_submitted',
                    'user' => $logbook->intern->user->name ?? 'Unknown',
                    'action' => "submitted logbook entry: {$logbook->title}",
                    'time' => $logbook->created_at->diffForHumans(),
                    'timestamp' => $logbook->created_at,
                    'icon' => 'book-open',
                    'color' => 'purple',
                ];
            });

        // Get user activities
        $userActivities = UserActivity::with('user')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'type' => 'user_activity',
                    'user' => $activity->user->name ?? 'Unknown',
                    'action' => $activity->action,
                    'time' => Carbon::parse($activity->created_at)->diffForHumans(),
                    'timestamp' => Carbon::parse($activity->created_at),
                    'icon' => 'activity',
                    'color' => 'yellow',
                ];
            });

        // Merge and sort all activities
        $activities = $activities
            ->merge($recentUsers)
            ->merge($recentTasks)
            ->merge($recentLogbooks)
            ->merge($userActivities)
            ->sortByDesc('timestamp')
            ->take($limit)
            ->values();

        return $activities;
    }

    /**
     * Get pending items that need attention
     */
    private function getPendingItems()
    {
        $pendingItems = collect();

        // Pending logbook reviews
        $pendingLogbooks = Logbook::with(['intern.user'])
            ->where('status', 'pending')
            ->whereNull('reviewed_at')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($logbook) {
                return [
                    'id' => $logbook->id,
                    'type' => 'Logbook Review',
                    'title' => $logbook->title,
                    'user' => $logbook->intern->user->name ?? 'Unknown',
                    'status' => 'pending',
                    'priority' => $logbook->created_at->diffInDays(now()) > 3 ? 'urgent' : 'normal',
                    'created_at' => $logbook->created_at,
                    'action_url' => "/admin/logbooks/{$logbook->id}/review",
                ];
            });

        // Overdue tasks
        $overdueTasks = Task::with(['specialty', 'assigner'])
            ->where('due_date', '<', now())
            ->where('status', '!=', 'done')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'type' => 'Overdue Task',
                    'title' => $task->title,
                    'user' => $task->specialty->name ?? 'Multiple Specialties',
                    'status' => 'urgent',
                    'priority' => 'urgent',
                    'created_at' => $task->created_at,
                    'action_url' => "/admin/tasks/{$task->id}",
                ];
            });

        // Inactive users (no login in 30 days)
        $inactiveUsers = User::where('last_login', '<', now()->subDays(30))
            ->where('is_active', true)
            ->latest('last_login')
            ->limit(3)
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'type' => 'Inactive User',
                    'title' => 'User Account Review',
                    'user' => $user->name,
                    'status' => 'pending',
                    'priority' => 'normal',
                    'created_at' => $user->last_login ? Carbon::parse($user->last_login) : $user->created_at,
                    'action_url' => "/admin/users/{$user->id}",
                ];
            });

        return $pendingItems
            ->merge($pendingLogbooks)
            ->merge($overdueTasks)
            ->merge($inactiveUsers)
            ->sortBy([
                ['priority', 'desc'],
                ['created_at', 'desc'],
            ])
            ->take(10)
            ->values();
    }

    /**
     * Get system statistics
     */
    private function getSystemStats()
    {
        return [
            'total_specialties' => Specialty::count(),
            'active_specialties' => Specialty::where('status', 'active')->count(),
            'total_supervisors' => Supervisor::count(),
            'total_interns' => Intern::count(),
            'active_interns' => Intern::whereNull('end_date')
                ->orWhere('end_date', '>', now())
                ->count(),
            'completed_internships' => Intern::whereNotNull('end_date')
                ->where('end_date', '<=', now())
                ->count(),
            'pending_logbooks' => Logbook::where('status', 'pending')
                ->whereNull('reviewed_at')
                ->count(),
            'total_tasks' => Task::count(),
            'completed_tasks' => Task::where('status', 'done')->count(),
        ];
    }

    /**
     * Get task overview statistics
     */
    private function getTaskOverview()
    {
        $totalTasks = Task::count();
        $completedTasks = Task::where('status', 'done')->count();
        $inProgressTasks = Task::where('status', 'in_progress')->count();
        $pendingTasks = Task::where('status', 'pending')->count();

        $completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0;

        return [
            'total_tasks' => $totalTasks,
            'completed_tasks' => $completedTasks,
            'in_progress_tasks' => $inProgressTasks,
            'pending_tasks' => $pendingTasks,
            'completion_rate' => $completionRate,
            'overdue_tasks' => Task::where('due_date', '<', now())
                ->where('status', '!=', 'done')
                ->count(),
        ];
    }

    /**
     * Get logbook statistics
     */
    private function getLogbookStats()
    {
        $totalLogbooks = Logbook::count();
        $submittedLogbooks = Logbook::where('status', 'pending')->count();
        $reviewedLogbooks = Logbook::whereNotNull('reviewed_at')->count();
        $pendingReview = Logbook::where('status', 'pending')
            ->whereNull('reviewed_at')
            ->count();

        $avgHoursWorked = Logbook::avg('hours_worked') ?? 0;

        return [
            'total_logbooks' => $totalLogbooks,
            'submitted_logbooks' => $submittedLogbooks,
            'reviewed_logbooks' => $reviewedLogbooks,
            'pending_review' => $pendingReview,
            'average_hours_worked' => round($avgHoursWorked, 1),
            'this_week_submissions' => Logbook::where('created_at', '>=', now()->startOfWeek())->count(),
        ];
    }

    /**
     * Get specialty statistics
     */
    private function getSpecialtyStats()
    {
        return Specialty::with(['interns', 'supervisors'])
            ->get()
            ->map(function ($specialty) {
                $activeInterns = $specialty->interns()
                    ->whereNull('end_date')
                    ->orWhere('end_date', '>', now())
                    ->count();

                return [
                    'id' => $specialty->id,
                    'name' => $specialty->name,
                    'total_interns' => $specialty->interns->count(),
                    'active_interns' => $activeInterns,
                    'total_supervisors' => $specialty->supervisors->count(),
                    'status' => $specialty->status,
                ];
            });
    }

    /**
     * Convert period string to start date
     */
    private function getStartDateFromPeriod($period)
    {
        switch ($period) {
            case '24h':
                return now()->subDay();
            case '7d':
                return now()->subDays(7);
            case '30d':
                return now()->subDays(30);
            case '90d':
                return now()->subDays(90);
            default:
                return now()->subDays(7);
        }
    }

    /**
     * Get All Interns
     *
     * Retrieve a list of all interns in the system with their user profiles,
     * specialty assignments, and associated supervisors.
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "user_id": 5,
     *       "name": "John Intern",
     *       "email": "john@example.com",
     *       "status": "active",
     *       "joinDate": "2026-01-15",
     *       "specialty": "Software Development",
     *       "institution": "University of Buea",
     *       "matricNumber": "INT-2026-0001",
     *       "hortNumber": "HORT001",
     *       "startDate": "2026-01-20",
     *       "endDate": "2026-06-20",
     *       "supervisors": [{"id": 1, "name": "Jane Supervisor", "email": "jane@example.com"}],
     *       "role": "intern"
     *     }
     *   ]
     * }
     */
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

    /**
     * Get All Supervisors
     *
     * Retrieve a list of all supervisors in the system with their user profiles,
     * specialty assignments, and the count of interns they oversee.
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "user_id": 3,
     *       "name": "Jane Supervisor",
     *       "email": "jane@example.com",
     *       "status": "active",
     *       "joinDate": "2025-06-01",
     *       "specialty": "Software Development",
     *       "department": "Engineering",
     *       "position": "Senior Developer",
     *       "internCount": 5,
     *       "role": "supervisor"
     *     }
     *   ]
     * }
     */
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

    /**
     * Get All Admins
     *
     * Retrieve a list of all administrator accounts with their user profiles
     * and assigned permissions.
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "user_id": 1,
     *       "name": "Super Admin",
     *       "email": "admin@trazor.com",
     *       "status": "active",
     *       "joinDate": "2025-01-01",
     *       "department": "Administration",
     *       "permissions": ["user_management", "analytics"],
     *       "role": "admin"
     *     }
     *   ]
     * }
     */
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

    /**
     * Get All Users
     *
     * Retrieve a consolidated list of all users (interns, supervisors, and admins)
     * in a single API call. Useful for displaying a unified user management view.
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {"id": 1, "user_id": 5, "name": "John", "role": "intern", "status": "active"},
     *     {"id": 2, "user_id": 3, "name": "Jane", "role": "supervisor", "status": "active"},
     *     {"id": 3, "user_id": 1, "name": "Admin", "role": "admin", "status": "active"}
     *   ]
     * }
     */
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

    /**
     * Show User Details
     *
     * Retrieve detailed information about a specific user including their profile,
     * role-specific data, settings, and recent activity history.
     *
     * @urlParam id integer required The user ID. Example: 5
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "id": 5,
     *     "name": "John Doe",
     *     "email": "john@example.com",
     *     "status": "active",
     *     "role": "intern",
     *     "specialty": "Software Development",
     *     "institution": "University of Buea",
     *     "settings": {"email_notifications": true, "profile_public": true, "two_factor_auth": false},
     *     "activities": [{"action": "Logbook filled", "time": "January 17, 2026 10:30 AM"}]
     *   }
     * }
     * @response 404 {"message": "No query results for model [App\\Models\\User]"}
     */
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

    /**
     * Toggle User Status
     *
     * Activate or deactivate a user account. Toggles the `is_active` status
     * of the specified user. Deactivated users cannot log in.
     *
     * @urlParam id integer required The user ID. Example: 5
     *
     * @response 200 {
     *   "success": true,
     *   "message": "User status updated successfully",
     *   "data": {"id": 5, "status": "inactive"}
     * }
     * @response 404 {"success": false, "message": "User not found"}
     * @response 500 {"success": false, "message": "Failed to update user status"}
     */
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

    /**
     * Update User
     *
     * Update a user's profile information and role-specific data.
     * The fields required depend on the user's role.
     *
     * **Role-specific fields:**
     * - **Intern**: institution, specialty, hort_number, start_date, end_date
     * - **Supervisor**: specialty
     * - **Admin/Super Admin**: No additional fields required
     *
     * @urlParam id integer required The user ID. Example: 5
     *
     * @bodyParam name string required The user's full name. Example: John Doe
     * @bodyParam email string required The user's email. Example: john@example.com
     * @bodyParam role string required The user's role. Example: intern
     * @bodyParam status string required Account status. Example: active
     * @bodyParam phone string Phone number. Example: +237612345678
     * @bodyParam location string Location. Example: Douala, Cameroon
     * @bodyParam bio string Biography. Example: Experienced developer
     * @bodyParam institution string Required for intern. Example: University of Buea
     * @bodyParam specialty integer Required for intern/supervisor. Example: 1
     *
     * @response 200 {
     *   "message": "User updated successfully",
     *   "user": {"id": 5, "name": "John Doe", "email": "john@example.com"}
     * }
     * @response 404 {"message": "No query results for model [App\\Models\\User]"}
     * @response 422 {"errors": {"email": ["The email has already been taken."]}}
     * @response 500 {"message": "Error updating user"}
     */
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
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
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
                        'start_date' => $internData['start_date'],
                        'end_date' => $internData['end_date'],
                    ]);
                } else {
                    $user->intern()->create([
                        'specialty_id' => $internData['specialty'],
                        'institution' => $internData['institution'],
                        'hort_number' => $internData['hort_number'],
                        'start_date' => $internData['start_date'],
                        'end_date' => $internData['end_date'],
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

    /**
     * Get User Activities
     *
     * Retrieve the activity log for a specific user, showing all actions
     * they have performed in the system, ordered by most recent first.
     *
     * @urlParam userId integer required The user ID. Example: 5
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {"id": 1, "user_id": 5, "action": "User logged in", "created_at": "2026-01-17T10:30:00Z"},
     *     {"id": 2, "user_id": 5, "action": "Logbook filled", "created_at": "2026-01-17T09:00:00Z"}
     *   ]
     * }
     * @response 404 {"message": "No query results for model [App\\Models\\User]"}
     */
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
