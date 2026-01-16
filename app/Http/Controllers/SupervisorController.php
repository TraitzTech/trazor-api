<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Announcement;
use App\Models\Attachment;
use App\Models\Intern;
use App\Models\Supervisor;
use App\Models\Task;
use Illuminate\Http\Request;

class SupervisorController extends Controller
{
    /**
     * Get all interns with the same specialty as a given supervisor.
     *
     * @return \Illuminate\Http\JsonResponse
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

        return response()->json(['interns' => $interns], 200);
    }

    /**
     * Get announcements relevant to the authenticated supervisor.
     *
     * Returns announcements targeted to:
     * - All users
     * - Supervisors specifically
     * - The supervisor's specialty
     *
     * @return \Illuminate\Http\JsonResponse
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
            'announcements' => $announcements,
            'count' => $announcements->count(),
        ]);
    }

    /**
     * Get all tasks for the supervisor's specialty.
     *
     * Returns tasks with their assigned interns, progress summary,
     * and related data for the supervisor's specialty.
     *
     * @return \Illuminate\Http\JsonResponse
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
            ->get()
            ->map(function ($task) {
                return [
                    'id' => $task->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'due_date' => $task->due_date,
                    'status' => $task->status,
                    'specialty' => $task->specialty,
                    'assigner' => $task->assigner,
                    'created_at' => $task->created_at,
                    'updated_at' => $task->updated_at,
                    'progress' => $task->getProgressSummary(),
                    'interns' => $task->internsWithStatus->map(function ($intern) {
                        return [
                            'id' => $intern->id,
                            'user' => $intern->user,
                            'status' => $intern->pivot->status,
                            'started_at' => $intern->pivot->started_at,
                            'completed_at' => $intern->pivot->completed_at,
                            'intern_notes' => $intern->pivot->intern_notes,
                        ];
                    }),
                ];
            });

        return response()->json([
            'tasks' => $tasks,
            'count' => $tasks->count(),
            'specialty' => $supervisor->specialty,
        ]);
    }

    /**
     * Get a specific task with full details for the supervisor.
     *
     * @return \Illuminate\Http\JsonResponse
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
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'due_date' => $task->due_date,
                'status' => $task->status,
                'specialty' => $task->specialty,
                'assigner' => $task->assigner,
                'created_at' => $task->created_at,
                'updated_at' => $task->updated_at,
                'progress' => $task->getProgressSummary(),
                'interns' => $task->internsWithStatus->map(function ($intern) {
                    return [
                        'id' => $intern->id,
                        'user' => $intern->user,
                        'status' => $intern->pivot->status,
                        'started_at' => $intern->pivot->started_at,
                        'completed_at' => $intern->pivot->completed_at,
                        'intern_notes' => $intern->pivot->intern_notes,
                    ];
                }),
                'attachments' => $task->attachments,
                'comments' => $task->comments,
            ],
        ]);
    }

    /**
     * Get all task submissions (attachments) for the supervisor's specialty tasks.
     *
     * Returns all file submissions from interns for tasks in the supervisor's specialty.
     *
     * @return \Illuminate\Http\JsonResponse
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
            ->get()
            ->map(function ($attachment) {
                return [
                    'id' => $attachment->id,
                    'task' => [
                        'id' => $attachment->task->id,
                        'title' => $attachment->task->title,
                    ],
                    'uploaded_by' => $attachment->uploader,
                    'original_name' => $attachment->original_name,
                    'file_size' => $attachment->file_size,
                    'mime_type' => $attachment->mime_type,
                    'description' => $attachment->description,
                    'path' => $attachment->path,
                    'created_at' => $attachment->created_at,
                ];
            });

        return response()->json([
            'submissions' => $submissions,
            'count' => $submissions->count(),
        ]);
    }

    /**
     * Get task submissions for a specific task.
     *
     * @return \Illuminate\Http\JsonResponse
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
            'submissions' => $submissions,
            'count' => $submissions->count(),
        ]);
    }

    /**
     * Get supervisor dashboard summary.
     *
     * Returns overview statistics for the supervisor's specialty.
     *
     * @return \Illuminate\Http\JsonResponse
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

        return response()->json([
            'specialty' => $supervisor->specialty,
            'stats' => [
                'interns_count' => $internsCount,
                'tasks_count' => $tasksCount,
                'pending_tasks' => $pendingTasks,
                'in_progress_tasks' => $inProgressTasks,
                'completed_tasks' => $completedTasks,
                'submissions_count' => $submissionsCount,
            ],
            'recent_submissions' => $recentSubmissions,
        ]);
    }
}
