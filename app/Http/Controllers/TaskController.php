<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Intern;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TaskController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            $tasks = Task::with(['interns', 'specialty', 'comments', 'attachments'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $tasks,
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
     * Show the form for creating a new resource.
     */
    public function create() {}

    /**
     * Store a newly created resource in storage.
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

            $task = Task::create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'status' => $validated['status'] ?? 'pending',
                'assigned_by' => auth()->id(),
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
                'data' => $task,
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
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $task = Task::with(['interns', 'specialty', 'comments.user', 'attachments'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $task,
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
     * Show the form for editing the specified resource.
     */
    public function edit($id) {}

    /**
     * Update the specified resource in storage.
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
                'data' => $task,
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
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $task = Task::findOrFail($id);

            // Detach all interns
            $task->interns()->detach();

            // Delete the task (comments and attachments will be deleted by cascade if set up)
            $task->delete();

            return response()->json([
                'success' => true,
                'message' => 'Task deleted successfully',
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete task',
                'error' => $e->getMessage(),
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
     * Update individual intern's task status
     */
    public function updateInternStatus(Request $request, $taskId)
    {

        $internId = AuthHelper::getUserFromBearerToken($request)->intern->id;
        try {
            $validated = $request->validate([
                'status' => 'required|in:pending,in_progress,done',
                'notes' => 'nullable|string|max:500',
            ]);

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
                    'task' => $task,
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
     * Get task with individual intern statuses and progress
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
                    'task' => $task,
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
     * Get intern's personal task dashboard
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
                ->with(['specialty', 'attachments'])
                ->orderBy('created_at', 'desc')
                ->get();

            $statistics = $intern->getTaskStatistics();

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => $statistics,
                    'tasks' => $tasks,
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
}
