<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Announcement;
use App\Models\Attachment;
use App\Models\Intern;
use App\Models\Supervisor;
use App\Models\Task;
use App\Http\Resources\AnnouncementResource;
use App\Http\Resources\AttachmentResource;
use App\Http\Resources\InternResource;
use App\Http\Resources\SpecialtyResource;
use App\Http\Resources\SupervisorDashboardStatsResource;
use App\Http\Resources\TaskWithProgressResource;
use Illuminate\Http\Request;

/**
 * @tags Supervisor
 */
class SupervisorController extends Controller
{
    /**
     * Get Interns by Specialty
     *
     * Retrieve all interns that share the same specialty as the authenticated supervisor.
     */
    public function getInternsBySupervisorSpecialty(Request $request)
    {
        $supervisor = AuthHelper::getUserFromBearerToken($request)?->supervisor;

        if (! $supervisor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Eager load the specialty for the supervisor to avoid N+1 query problem
        $supervisor?->load('specialty');

        // Check if the supervisor has a specialty assigned
        if (! $supervisor?->specialty) {
            return response()->json(['message' => 'Supervisor does not have a specialty assigned.'], 404);
        }

        // Retrieve interns who have the same specialty_id as the supervisor
        // Eager load the 'user' and 'specialty' relationships for interns for complete data
        $interns = Intern::where('specialty_id', $supervisor->specialty->id)
            ->with(['user', 'specialty'])
            ->get();

        if ($interns->isEmpty()) {
            return response()->json(['message' => 'No interns found with the same specialty as this supervisor.'], 404);
        }

        return response()->json([
            'interns' => InternResource::collection($interns),
        ], 200);
    }

    /**
     * Get Supervisor Announcements
     *
     * Retrieve announcements relevant to the authenticated supervisor.
     * Returns announcements targeted to all users, supervisors specifically,
     * or the supervisor's specialty.
     */
    public function getAnnouncements(Request $request)
    {
        $user = AuthHelper::getUserFromBearerToken($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $user->hasRole('supervisor')) {
            return response()->json(['message' => 'User is not a supervisor'], 403);
        }

        $specialtyId = $user->supervisor?->specialty_id;

        $announcements = Announcement::with(['author', 'specialty'])
            ->where(function ($query) use ($specialtyId) {
                $query->where('target', 'all')
                    ->orWhere('target', 'supervisor')
                    ->orWhere(function ($q) use ($specialtyId) {
                        $q->where('target', 'specialty')
                            ->where('specialty_id', $specialtyId);
                    });
            })
            ->latest()
            ->get();

        return response()->json([
            'announcements' => AnnouncementResource::collection($announcements),
            'count' => $announcements->count(),
        ]);
    }

    /**
     * Get Specialty Tasks
     *
     * Retrieve all tasks for the supervisor's specialty with assigned interns and progress summaries.
     */
    public function getTasks(Request $request)
    {
        $user = AuthHelper::getUserFromBearerToken($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $user->hasRole('supervisor')) {
            return response()->json(['message' => 'User is not a supervisor'], 403);
        }

        $supervisor = $user->supervisor;

        if (! $supervisor || ! $supervisor->specialty_id) {
            return response()->json(['message' => 'Supervisor does not have a specialty assigned'], 404);
        }

        $tasks = Task::with(['specialty', 'assigner', 'internsWithStatus.user'])
            ->where('specialty_id', $supervisor->specialty_id)
            ->latest()
            ->get();

        return response()->json([
            'tasks' => TaskWithProgressResource::collection($tasks),
            'count' => $tasks->count(),
            'specialty' => new SpecialtyResource($supervisor->specialty),
        ]);
    }

    /**
     * Get Task Details
     *
     * Retrieve full details of a specific task including assigned interns and their progress.
     *
    * @urlParam taskId integer required The ID of the task. Example: 1
     */
    public function getTask(Request $request, $taskId)
    {
        $user = AuthHelper::getUserFromBearerToken($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $user->hasRole('supervisor')) {
            return response()->json(['message' => 'User is not a supervisor'], 403);
        }

        $supervisor = $user->supervisor;

        if (! $supervisor || ! $supervisor->specialty_id) {
            return response()->json(['message' => 'Supervisor does not have a specialty assigned'], 404);
        }

        $task = Task::with(['specialty', 'assigner', 'internsWithStatus.user', 'attachments.uploader', 'comments.user'])
            ->where('specialty_id', $supervisor->specialty_id)
            ->where('id', $taskId)
            ->first();

        if (! $task) {
            return response()->json(['message' => 'Task not found or not in your specialty'], 404);
        }

        return response()->json([
            'task' => new TaskWithProgressResource($task),
        ]);
    }

    /**
     * Get All Task Submissions
     *
     * Retrieve all file submissions (attachments) from interns for tasks in the supervisor's specialty.
     */
    public function getTaskSubmissions(Request $request)
    {
        $user = AuthHelper::getUserFromBearerToken($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $user->hasRole('supervisor')) {
            return response()->json(['message' => 'User is not a supervisor'], 403);
        }

        $supervisor = $user->supervisor;

        if (! $supervisor || ! $supervisor->specialty_id) {
            return response()->json(['message' => 'Supervisor does not have a specialty assigned'], 404);
        }

        // Get all task IDs for the supervisor's specialty
        $taskIds = Task::where('specialty_id', $supervisor->specialty_id)->pluck('id');

        // Get all attachments for these tasks
        $submissions = Attachment::with(['task', 'uploader'])
            ->whereIn('task_id', $taskIds)
            ->latest()
            ->get();

        return response()->json([
            'submissions' => AttachmentResource::collection($submissions),
            'count' => $submissions->count(),
        ]);
    }

    /**
     * Get Task Submissions by Task
     *
     * Retrieve all file submissions for a specific task in the supervisor's specialty.
     *
    * @urlParam taskId integer required The ID of the task. Example: 1
     */
    public function getTaskSubmissionsForTask(Request $request, $taskId)
    {
        $user = AuthHelper::getUserFromBearerToken($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $user->hasRole('supervisor')) {
            return response()->json(['message' => 'User is not a supervisor'], 403);
        }

        $supervisor = $user->supervisor;

        if (! $supervisor || ! $supervisor->specialty_id) {
            return response()->json(['message' => 'Supervisor does not have a specialty assigned'], 404);
        }

        // Verify the task belongs to supervisor's specialty
        $task = Task::where('id', $taskId)
            ->where('specialty_id', $supervisor->specialty_id)
            ->first();

        if (! $task) {
            return response()->json(['message' => 'Task not found or not in your specialty'], 404);
        }

        $submissions = Attachment::with(['uploader'])
            ->where('task_id', $taskId)
            ->latest()
            ->get();

        return response()->json([
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
            ],
            'submissions' => AttachmentResource::collection($submissions),
            'count' => $submissions->count(),
        ]);
    }

    /**
     * Get Supervisor Dashboard
    *
    * Retrieve dashboard summary with statistics for the supervisor's specialty including
    * intern count, task breakdown by status, submission count, and recent submissions.
     */
    public function getDashboard(Request $request)
    {
        $user = AuthHelper::getUserFromBearerToken($request);

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $user->hasRole('supervisor')) {
            return response()->json(['message' => 'User is not a supervisor'], 403);
        }

        $supervisor = $user->supervisor;

        if (! $supervisor || ! $supervisor->specialty_id) {
            return response()->json(['message' => 'Supervisor does not have a specialty assigned'], 404);
        }

        $specialtyId = $supervisor->specialty_id;

        // Count interns in specialty
        $internsCount = Intern::where('specialty_id', $specialtyId)->count();

        // Get tasks for specialty
        $tasks = Task::where('specialty_id', $specialtyId)->get();
        $tasksCount = $tasks->count();

        // Task status breakdown
        $pendingTasks = $tasks->where('status', 'pending')->count();
        $inProgressTasks = $tasks->where('status', 'in_progress')->count();
        $completedTasks = $tasks->where('status', 'completed')->count();

        // Count submissions
        $taskIds = $tasks->pluck('id');
        $submissionsCount = Attachment::whereIn('task_id', $taskIds)->count();

        // Recent submissions
        $recentSubmissions = Attachment::with(['task', 'uploader'])
            ->whereIn('task_id', $taskIds)
            ->latest()
            ->limit(5)
            ->get();

        $stats = [
            'interns_count' => $internsCount,
            'tasks_count' => $tasksCount,
            'pending_tasks' => $pendingTasks,
            'in_progress_tasks' => $inProgressTasks,
            'completed_tasks' => $completedTasks,
            'submissions_count' => $submissionsCount,
        ];

        return response()->json([
            'specialty' => new SpecialtyResource($supervisor->specialty),
            'stats' => new SupervisorDashboardStatsResource($stats),
            'recent_submissions' => AttachmentResource::collection($recentSubmissions),
        ]);
    }
}
