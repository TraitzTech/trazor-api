<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Http\Resources\InternTaskStatisticsResource;
use App\Http\Resources\TaskResource;
use App\Models\Intern;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * @tags Tasks
 */
class TaskController extends Controller
{
    /**
     * List All Tasks
     *
     * Retrieve all tasks with their assigned interns, specialty, comments, and attachments.
     *
     */
    public function index()
    {
        try {
            $tasks = Task::with(['interns.user', 'specialty', 'comments.user', 'attachments'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => TaskResource::collection($tasks),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve tasks',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create Task Form
     *
     * Placeholder for form creation (not used in API).
     */
    public function create() {}

    /**
     * Create New Task
     *
     * Create a new task and optionally assign it to specific interns or all interns in a specialty.
     * Push notifications are sent to assigned interns.
     *
     * @bodyParam title string required The task title. Example: Build REST API
     * @bodyParam description string optional Detailed description of the task. Example: Create RESTful endpoints for user management
     * @bodyParam due_date date optional Due date (must be after today). Example: 2025-02-15
     * @bodyParam status string optional Task status: pending, in_progress, done. Defaults to pending. Example: pending
     * @bodyParam specialty_id integer optional Specialty ID to assign task to. Example: 2
    * @bodyParam intern_ids array optional Specific intern IDs to assign. If not provided, assigns to all interns in specialty. Example: [1, 2, 3]
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'due_date' => 'nullable|date|after:today',
                'status' => 'in:pending,in_progress,done',
                'specialty_id' => 'nullable|exists:specialties,id',
                'intern_ids' => 'nullable|array',
                'intern_ids.*' => 'exists:interns,id',
            ]);

            $user = AuthHelper::getUserFromBearerToken($request);

            $task = Task::create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'status' => $validated['status'] ?? 'pending',
                'assigned_by' => $user->id,
                'specialty_id' => $validated['specialty_id'] ?? null,
            ]);

            // Determine which interns to assign
            $assignedInternIds = [];
            if (! empty($validated['intern_ids'])) {
                $task->interns()->attach($validated['intern_ids']);
                $assignedInternIds = $validated['intern_ids'];
            } elseif ($task->specialty->id) {
                $interns = Intern::where('specialty_id', $task->specialty->id)->pluck('id');
                $task->interns()->attach($interns);
                $assignedInternIds = $interns->toArray();
            } else {
                $allInterns = Intern::pluck('id');
                $task->interns()->attach($allInterns);
                $assignedInternIds = $allInterns->toArray();
            }

            // Send notifications to assigned interns
            $this->sendTaskNotifications($task, $assignedInternIds, 'created');

            $task->load(['interns', 'specialty']);

            return response()->json([
                'success' => true,
                'message' => 'Task created successfully',
                'data' => new TaskResource($task),
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Task Details
     *
     * Retrieve a specific task with full details including assigned interns (with their individual
     * submission status), specialty, comments, attachments, and progress summary.
     *
    * @urlParam id integer required The ID of the task. Example: 1
    */
    public function show($id)
    {
        try {
            $task = Task::with([
                // Load interns with their individual task status (pivot data)
                'interns' => function ($query) {
                    $query->with('user', 'specialty')
                        ->withPivot(['status', 'started_at', 'completed_at', 'intern_notes'])
                        ->orderBy('pivot_created_at', 'desc'); // Order by when they were assigned
                },
                'specialty',
                'comments' => function ($query) {
                    $query->with('user')
                        ->orderBy('created_at', 'desc');
                },
                'attachments' => function ($query) {
                    $query->orderBy('created_at', 'desc');
                },
            ])->findOrFail($id);

            // Transform the data to include pivot information more clearly
            $task->interns->transform(function ($intern) {
                return [
                    'id' => $intern->id,
                    'user_id' => $intern->user_id,
                    'institution' => $intern->institution,
                    'matric_number' => $intern->matric_number,
                    'hort_number' => $intern->hort_number,
                    'user' => $intern->user,
                    'specialty' => $intern->specialty,
                    // Pivot data (individual task submission info)
                    'submission' => [
                        'status' => $intern->pivot->status ?? 'pending',
                        'started_at' => $intern->pivot->started_at,
                        'completed_at' => $intern->pivot->completed_at,
                        'intern_notes' => $intern->pivot->intern_notes,
                        'assigned_at' => $intern->pivot->created_at,
                        'updated_at' => $intern->pivot->updated_at,
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'data' => new TaskResource($task),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Edit Task Form
     *
     * Placeholder for edit form (not used in API).
     */
    public function edit($id) {}

    /**
     * Update Task
     *
     * Update an existing task's details and optionally reassign interns.
     * Push notifications are sent when significant changes are made.
     *
     * @urlParam id integer required The ID of the task to update. Example: 1
     * @bodyParam title string required The task title. Example: Updated API Task
     * @bodyParam description string optional Detailed description. Example: Updated requirements
     * @bodyParam due_date date optional Due date. Example: 2025-03-01
     * @bodyParam status string optional Status: pending, in_progress, done. Example: in_progress
    * @bodyParam specialty_id integer optional Specialty ID. Example: 2
    * @bodyParam intern_ids array optional Intern IDs to assign. Example: [1, 2]
     */
    public function update(Request $request, $id)
    {
        try {
            $task = Task::findOrFail($id);

            // Store original values for comparison
            $originalStatus = $task->status;
            $originalTitle = $task->title;
            $originalDueDate = $task->due_date;
            $originalInternIds = $task->interns->pluck('id')->toArray();

            $validated = $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'due_date' => 'nullable|date',
                'status' => 'in:pending,in_progress,done',
                'specialty_id' => 'nullable|exists:specialties,id',
                'intern_ids' => 'nullable|array',
                'intern_ids.*' => 'exists:interns,id',
            ]);

            $task->update([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'status' => $validated['status'] ?? $task->status,
                'specialty_id' => $validated['specialty_id'] ?? null,
            ]);

            // Handle intern assignments and get current assigned intern IDs
            $currentAssignedInternIds = [];
            if (isset($validated['intern_ids'])) {
                if (! empty($validated['intern_ids'])) {
                    $task->interns()->sync($validated['intern_ids']);
                    $currentAssignedInternIds = $validated['intern_ids'];
                } elseif ($task->specialty->id) {
                    $interns = Intern::where('specialty_id', $task->specialty->id)->pluck('id');
                    $task->interns()->sync($interns);
                    $currentAssignedInternIds = $interns->toArray();
                } else {
                    $allInterns = Intern::pluck('id');
                    $task->interns()->sync($allInterns);
                    $currentAssignedInternIds = $allInterns->toArray();
                }
            } else {
                // If intern_ids not provided, keep existing assignments
                $currentAssignedInternIds = $originalInternIds;
            }

            // Determine what changed and send appropriate notifications
            $changes = $this->detectTaskChanges($task, $originalStatus, $originalTitle, $originalDueDate, $originalInternIds, $currentAssignedInternIds);

            if (! empty($changes)) {
                $this->sendTaskUpdateNotifications($task, $changes, $currentAssignedInternIds, $originalInternIds);
            }

            $task->load(['interns', 'specialty']);

            return response()->json([
                'success' => true,
                'message' => 'Task updated successfully',
                'data' => new TaskResource($task),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found',
            ], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Detect what changes were made to the task
     */
    private function detectTaskChanges($task, $originalStatus, $originalTitle, $originalDueDate, $originalInternIds, $currentAssignedInternIds)
    {
        $changes = [];

        if ($task->status !== $originalStatus) {
            $changes['status'] = [
                'from' => $originalStatus,
                'to' => $task->status,
            ];
        }

        if ($task->title !== $originalTitle) {
            $changes['title'] = [
                'from' => $originalTitle,
                'to' => $task->title,
            ];
        }

        if ($task->due_date !== $originalDueDate) {
            $changes['due_date'] = [
                'from' => $originalDueDate,
                'to' => $task->due_date,
            ];
        }

        if (array_diff($currentAssignedInternIds, $originalInternIds) || array_diff($originalInternIds, $currentAssignedInternIds)) {
            $changes['assignments'] = [
                'from' => $originalInternIds,
                'to' => $currentAssignedInternIds,
            ];
        }

        return $changes;
    }

    /**
     * Send smart notifications based on task changes
     */
    private function sendTaskUpdateNotifications($task, $changes, $currentAssignedInternIds, $originalInternIds)
    {
        try {
            // Get all affected intern IDs (current + previously assigned)
            $allAffectedInternIds = array_unique(array_merge($currentAssignedInternIds, $originalInternIds));

            // Get users associated with affected interns who have device tokens
            $recipients = User::whereHas('intern', function ($query) use ($allAffectedInternIds) {
                $query->whereIn('id', $allAffectedInternIds);
            })
                ->whereNotNull('device_token')
                ->get();

            foreach ($recipients as $recipient) {
                $isCurrentlyAssigned = in_array($recipient->intern->id, $currentAssignedInternIds);
                $wasAssigned = in_array($recipient->intern->id, $originalInternIds);

                // Generate personalized notification based on changes and user's assignment status
                $notification = $this->generatePersonalizedNotification($task, $changes, $isCurrentlyAssigned, $wasAssigned);

                if ($notification) {
                    app(NotificationController::class)->sendNotification(new Request([
                        'user_id' => $recipient->id,
                        'title' => $notification['title'],
                        'body' => $notification['body'],
                    ]));
                }
            }

        } catch (\Exception $e) {
            \Log::error('Failed to send task update notifications: '.$e->getMessage());
        }
    }

    /**
     * Generate personalized notification message based on changes and user's assignment status
     */
    private function generatePersonalizedNotification($task, $changes, $isCurrentlyAssigned, $wasAssigned)
    {
        $title = '';
        $body = '';

        // Handle assignment changes first
        if (isset($changes['assignments'])) {
            if (! $wasAssigned && $isCurrentlyAssigned) {
                // Newly assigned
                return [
                    'title' => '📋 New Task Assigned',
                    'body' => "You've been assigned to: {$task->title}".($task->due_date ? ' | Due: '.\Carbon\Carbon::parse($task->due_date)->format('M d, Y') : ''),
                ];
            } elseif ($wasAssigned && ! $isCurrentlyAssigned) {
                // Removed from task
                return [
                    'title' => '📋 Task Assignment Removed',
                    'body' => "You've been removed from task: {$task->title}",
                ];
            }
        }

        // Only notify currently assigned interns about other changes
        if (! $isCurrentlyAssigned) {
            return null;
        }

        // Handle status changes
        if (isset($changes['status'])) {
            $statusEmojis = [
                'pending' => '⏳',
                'in_progress' => '🔄',
                'done' => '✅',
            ];

            $statusLabels = [
                'pending' => 'Pending',
                'in_progress' => 'In Progress',
                'done' => 'Completed',
            ];

            $emoji = $statusEmojis[$task->status] ?? '📋';
            $statusLabel = $statusLabels[$task->status] ?? ucfirst($task->status);

            $title = "{$emoji} Task Status Updated";
            $body = "'{$task->title}' is now {$statusLabel}";
        }
        // Handle title changes
        elseif (isset($changes['title'])) {
            $title = '📝 Task Title Updated';
            $body = "Task renamed to: {$task->title}";
        }
        // Handle due date changes
        elseif (isset($changes['due_date'])) {
            $title = '📅 Task Due Date Updated';
            if ($task->due_date) {
                $body = "'{$task->title}' due date changed to: ".\Carbon\Carbon::parse($task->due_date)->format('M d, Y');
            } else {
                $body = "'{$task->title}' due date has been removed";
            }
        }
        // Generic update message
        else {
            $title = '📋 Task Updated';
            $body = "'{$task->title}' has been updated";
        }

        return ['title' => $title, 'body' => $body];
    }

    /**
     * Update Task Status
     *
     * Change the status of a task (admin/supervisor only). Sends notifications to all
     * assigned interns and relevant supervisors about the status change.
     *
    * @urlParam id integer required The ID of the task. Example: 1
    * @bodyParam status string required New status: pending, in_progress, done. Example: in_progress
     
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:pending,in_progress,done',
            ]);

            $task = Task::with(['interns'])->findOrFail($id);
            $oldStatus = $task->status;

            // Only continue if status is actually changing
            if ($oldStatus === $validated['status']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Status is already set to the specified value',
                ], 400);
            }

            $task->status = $validated['status'];
            $task->save();

            // Notify all stakeholders (assigned interns, task creator, supervisors)
            $assignedInternIds = $task->interns->pluck('id')->toArray();

            $changes = [
                'status' => [
                    'from' => $oldStatus,
                    'to' => $validated['status'],
                ],
            ];

            $this->sendTaskUpdateNotifications($task, $changes, $assignedInternIds, $assignedInternIds);

            // Also notify supervisors + creator explicitly about the update
            $this->notifyStakeholdersOfStatusChange($task, $validated['status']);

            $task->load(['interns', 'specialty']);

            return response()->json([
                'success' => true,
                'message' => 'Task status updated successfully',
                'data' => new TaskResource($task),
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found',
            ], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete Task
     *
     * Permanently delete a task including all its attachments (physical files), comments,
     * and intern assignments. This action cannot be undone.
     *
     * @urlParam id integer required The ID of the task to delete. Example: 1
     */
    public function destroy($id)
    {
        try {
            $task = Task::findOrFail($id);

            DB::beginTransaction();

            // Delete physical attachment files from storage
            if ($task->attachments && $task->attachments->count() > 0) {
                foreach ($task->attachments as $attachment) {
                    // Delete the physical file from storage
                    if ($attachment->file_path && Storage::exists($attachment->file_path)) {
                        Storage::delete($attachment->file_path);
                    }

                    // Delete the attachment record
                    $attachment->delete();
                }
            }

            // Delete all comments related to this task
            if ($task->comments && $task->comments->count() > 0) {
                $task->comments()->delete();
            }

            // Delete all task_intern pivot records (detach all interns)
            $task->interns()->detach();

            // Finally, delete the task itself
            $task->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Task and all related data deleted successfully',
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Task not found',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error deleting task: '.$e->getMessage(), [
                'task_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete task',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred while deleting the task',
            ], 500);
        }
    }

    /**
     * Send task notifications to assigned interns
     */
    private function sendTaskNotifications($task, $internIds, $action = 'created')
    {
        try {
            // Get users associated with the intern IDs who have device tokens
            $recipients = User::whereHas('intern', function ($query) use ($internIds) {
                $query->whereIn('id', $internIds);
            })
                ->whereNotNull('device_token')
                ->get();

            $notificationTitle = $action === 'created'
                ? "New Task Assigned: {$task->title}"
                : "Task Updated: {$task->title}";

            $notificationBody = $action === 'created'
                ? "You have been assigned a new task: {$task->title}"
                : "Task '{$task->title}' has been updated";

            if ($task->due_date) {
                $notificationBody .= ' | Due: '.\Carbon\Carbon::parse($task->due_date)->format('M d, Y');
            }

            // Send notification to each recipient
            foreach ($recipients as $recipient) {
                app(NotificationController::class)->sendNotification(new Request([
                    'user_id' => $recipient->id,
                    'title' => $notificationTitle,
                    'body' => $notificationBody,
                ]));
            }

        } catch (\Exception $e) {
            // Log the error but don't fail the main operation
            \Log::error('Failed to send task notifications: '.$e->getMessage());
        }
    }

    /**
     * Update Intern Task Status
     *
     * Update the authenticated intern's status for a specific task (start, complete, add notes).
     * This updates the pivot table record between the intern and task.
     *
    * @urlParam taskId integer required The ID of the task. Example: 1
    * @bodyParam status string optional New status: pending, in_progress, done. Example: in_progress
    * @bodyParam intern_notes string optional Notes from the intern about their progress. Example: Completed the API endpoints
     */
    public function updateInternStatus(Request $request, $taskId)
    {
        try {
            $user = AuthHelper::getUserFromBearerToken($request);
            $validated = $request->validate([
                'status' => 'required|in:pending,in_progress,done',
                'notes' => 'nullable|string|max:500',
                'intern_id' => 'nullable|exists:interns,id', // Only required for admins/supervisors
            ]);

            // Determine intern ID based on user role
            if ($user->hasRole(['admin', 'supervisor'])) {
                if (empty($validated['intern_id'])) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Intern ID is required for admin/supervisor',
                    ], 400);
                }
                $internId = $validated['intern_id'];
            } else {
                // For interns, use their own ID
                if (! $user->intern) {
                    return response()->json([
                        'success' => false,
                        'message' => 'User is not associated with an intern profile',
                    ], 403);
                }
                $internId = $user->intern->id;
            }

            $task = Task::findOrFail($taskId);
            $intern = Intern::findOrFail($internId);

            // Check if intern is assigned to this task
            if (! $task->interns()->where('intern_id', $internId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Intern is not assigned to this task',
                ], 400);
            }

            // Get current pivot data to check for status change
            $currentPivot = $task->interns()->where('intern_id', $internId)->first()->pivot;
            $oldStatus = $currentPivot->status ?? 'pending';

            $pivotData = ['status' => $validated['status']];

            // Add timestamps based on status
            if ($validated['status'] === 'in_progress' && $oldStatus !== 'in_progress') {
                $pivotData['started_at'] = now();
            } elseif ($validated['status'] === 'done' && $oldStatus !== 'done') {
                $pivotData['completed_at'] = now();
            }

            if (isset($validated['notes'])) {
                $pivotData['intern_notes'] = $validated['notes'];
            }

            // Update pivot table
            $task->interns()->updateExistingPivot($internId, $pivotData);

            // Send notification if status actually changed
            if ($oldStatus !== $validated['status']) {
                $this->notifyTaskProgress($task, $intern, $validated['status']);
            }

            // Get updated task with intern statuses
            $task->load(['internsWithStatus', 'specialty']);

            return response()->json([
                'success' => true,
                'message' => 'Task status updated successfully',
                'data' => [
                    'task' => new TaskResource($task),
                    'progress_summary' => $task->getProgressSummary(),
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Task or intern not found',
            ], 404);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update task status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Task with Progress
     *
     * Retrieve a task with individual intern statuses and overall progress summary.
     *
    * @urlParam id integer required The ID of the task. Example: 1
     
     */
    public function showWithProgress($id)
    {
        try {
            $task = Task::with([
                'internsWithStatus',
                'specialty',
                'comments.user',
                'attachments',
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'task' => new TaskResource($task),
                    'progress_summary' => $task->getProgressSummary(),
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Intern Dashboard
     *
     * Retrieve the authenticated intern's task dashboard with statistics and all assigned tasks.
     * Includes task details, attachments, comments, and progress information.
     *

     */
    public function getInternDashboard(Request $request)
    {
        try {
            $user = AuthHelper::getUserFromBearerToken($request);
            if (! $user->hasRole('intern')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. User must be an intern.',
                ], 403);
            }

            $intern = $user->intern;
            if (! $intern) {
                return response()->json([
                    'success' => false,
                    'message' => 'Intern profile not found',
                ], 404);
            }

            $tasks = $intern->tasksWithStatus()
                ->with([
                    'specialty',
                    'attachments',
                    'assigner:id,name,email', // Load the user who assigned the task
                    'comments.user:id,name,avatar', // Load comments with commenter info
                ])
                ->orderBy('created_at', 'desc')
                ->get();

            $statistics = $intern->getTaskStatistics();

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => new InternTaskStatisticsResource($statistics),
                    'tasks' => TaskResource::collection($tasks),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send notifications about individual intern progress
     */
    private function notifyTaskProgress($task, $intern, $status)
    {
        try {
            $statusLabels = [
                'pending' => 'marked as pending',
                'in_progress' => 'started working on',
                'done' => 'completed',
            ];

            $statusEmojis = [
                'pending' => '⏳',
                'in_progress' => '🔄',
                'done' => '✅',
            ];

            $message = $statusLabels[$status] ?? 'updated';
            $emoji = $statusEmojis[$status] ?? '📋';

            // Get progress summary for additional context
            $progress = $task->getProgressSummary();
            $progressText = '';
            if ($progress['total'] > 1) {
                $progressText = " | Progress: {$progress['done']}/{$progress['total']} ({$progress['completion_percentage']}%)";
            }

            // Notify task creator and supervisors
            $recipients = User::where('id', $task->assigned_by)
                ->orWhereHas('roles', function ($q) {
                    $q->where('name', 'supervisor');
                })
                ->whereNotNull('device_token')
                ->get();

            foreach ($recipients as $recipient) {
                app(NotificationController::class)->sendNotification(new Request([
                    'user_id' => $recipient->id,
                    'title' => "{$emoji} Task Progress Update",
                    'body' => "{$intern->name} has {$message} '{$task->title}'{$progressText}",
                ]));
            }

        } catch (\Exception $e) {
            \Log::error('Failed to send task progress notifications: '.$e->getMessage());
        }
    }

    /**
     * Notify task creator and supervisors about status update
     */
    private function notifyStakeholdersOfStatusChange($task, $newStatus)
    {
        try {
            $statusLabels = [
                'pending' => 'Pending ⏳',
                'in_progress' => 'In Progress 🔄',
                'done' => 'Completed ✅',
            ];

            $statusText = $statusLabels[$newStatus] ?? ucfirst($newStatus);
            $title = '📋 Task Status Changed';
            $body = "Status of '{$task->title}' has been changed to {$statusText}";

            $recipients = User::where('id', $task->assigned_by)
                ->orWhereHas('roles', function ($query) {
                    $query->where('name', 'supervisor');
                })
                ->whereNotNull('device_token')
                ->get();

            foreach ($recipients as $recipient) {
                app(NotificationController::class)->sendNotification(new Request([
                    'user_id' => $recipient->id,
                    'title' => $title,
                    'body' => $body,
                ]));
            }

        } catch (\Exception $e) {
            \Log::error('Failed to notify stakeholders of task status change: '.$e->getMessage());
        }
    }
}
