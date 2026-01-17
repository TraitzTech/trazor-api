<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * @tags Comments
 */
class CommentController extends Controller
{
    /**
     * List Comments
     *
     * Retrieve all comments, optionally filtered by task.
     * Each comment includes user and task information.
     *
     * @queryParam task_id integer Filter comments by task ID. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "body": "Great progress!",
     *       "user": {"id": 1, "name": "John Doe"},
     *       "task": {"id": 1, "title": "Complete Report"}
     *     }
     *   ]
     * }
     * @response 500 {"success": false, "message": "Failed to retrieve comments"}
     */
    public function index(Request $request)
    {
        try {
            $query = Comment::with(['user', 'task'])
                ->orderBy('created_at', 'desc');

            // Filter by task if task_id is provided
            if ($request->has('task_id')) {
                $query->where('task_id', $request->task_id);
            }

            $comments = $query->get();

            return response()->json([
                'success' => true,
                'data' => $comments,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve comments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request) {}

    /**
     * Notify all stakeholders about a new comment on a task
     */
    private function notifyNewComment(Task $task, Comment $comment)
    {
        try {
            // Get all users who should be notified:
            // 1. Task creator
            // 2. All assigned interns
            // 3. Supervisors

            $user = AuthHelper::getUserFromBearerToken(request());

            $recipients = User::where('id', $task->assigned_by)
                ->orWhereHas('intern', function ($query) use ($task) {
                    $query->whereIn('id', $task->interns->pluck('id'));
                })
                ->orWhereHas('roles', function ($query) {
                    $query->where('name', 'supervisor');
                })
                ->whereNotNull('device_token')
                ->where('id', '!=', $user->id) // Don't notify the comment author
                ->get();

            $commentAuthor = $comment->user->name ?? 'Someone';
            $commentPreview = strlen($comment->body) > 50
                ? substr($comment->body, 0, 50).'...'
                : $comment->body;

            foreach ($recipients as $recipient) {
                app(NotificationController::class)->sendNotification(new Request([
                    'user_id' => $recipient->id,
                    'title' => '💬 New comment on task: '.$task->title,
                    'body' => $commentAuthor.' commented: "'.$commentPreview.'"',
                    'data' => [
                        'task_id' => $task->id,
                        'comment_id' => $comment->id,
                        'type' => 'new_comment',
                    ],
                ]));
            }

        } catch (\Exception $e) {
            Log::error('Failed to send comment notifications: '.$e->getMessage());
        }
    }

    /**
     * Create Comment
     *
     * Add a new comment to a task. Automatically notifies all task stakeholders
     * (task creator, assigned interns, and supervisors) about the new comment.
     *
     * @bodyParam body string required The comment content (max 1000 characters). Example: Great progress on this task!
     * @bodyParam task_id integer required The task to comment on. Example: 1
     *
     * @response 201 {
     *   "success": true,
     *   "message": "Comment created successfully",
     *   "data": {
     *     "id": 1,
     *     "body": "Great progress on this task!",
     *     "user": {"id": 5, "name": "John Doe"},
     *     "task": {"id": 1, "title": "Complete Report"}
     *   }
     * }
     * @response 422 {"success": false, "message": "Validation failed", "errors": {}}
     * @response 500 {"success": false, "message": "Failed to create comment"}
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'body' => 'required|string|max:1000',
                'task_id' => 'required|exists:tasks,id',
            ]);

            $user = AuthHelper::getUserFromBearerToken($request);

            $id = $validated['task_id'];

            // Ensure task exists
            $task = Task::with(['interns.user'])->findOrFail($id);

            $comment = Comment::create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'body' => $validated['body'],
            ]);

            $comment->load(['user', 'task']);

            // Notify all stakeholders about the new comment
            $this->notifyNewComment($task, $comment);

            return response()->json([
                'success' => true,
                'message' => 'Comment created successfully',
                'data' => $comment,
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
                'message' => 'Failed to create comment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show Comment
     *
     * Retrieve details of a specific comment with user and task information.
     *
     * @urlParam id integer required The comment ID. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "body": "Great progress!",
     *     "user": {"id": 5, "name": "John Doe"},
     *     "task": {"id": 1, "title": "Complete Report"}
     *   }
     * }
     * @response 404 {"success": false, "message": "Comment not found"}
     * @response 500 {"success": false, "message": "Failed to retrieve comment"}
     */
    public function show($id)
    {
        try {
            $comment = Comment::with(['user', 'task'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $comment,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve comment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Task Comments
     *
     * Retrieve all comments for a specific task, ordered by most recent first.
     * Includes user information (name, email, avatar) for each comment.
     *
     * @urlParam taskId integer required The task ID. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "body": "Great progress!",
     *       "user": {"id": 5, "name": "John Doe", "email": "john@example.com", "avatar": null},
     *       "created_at": "2026-01-17T10:30:00Z"
     *     }
     *   ]
     * }
     * @response 404 {"success": false, "message": "Task not found"}
     * @response 500 {"success": false, "message": "Failed to retrieve comments"}
     */
    public function getTaskComments($taskId)
    {
        try {
            // Verify task exists
            $task = Task::findOrFail($taskId);

            $comments = Comment::with(['user' => function ($query) {
                $query->select('id', 'name', 'email', 'avatar');
            }])
                ->where('task_id', $taskId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $comments,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve comments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id) {}

    /**
     * Update Comment
     *
     * Update an existing comment. Only the comment author or an admin can edit a comment.
     *
     * @urlParam id integer required The comment ID. Example: 1
     *
     * @bodyParam body string required The updated comment content (max 1000 characters). Example: Updated comment text
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Comment updated successfully",
     *   "data": {"id": 1, "body": "Updated comment text"}
     * }
     * @response 403 {"success": false, "message": "Unauthorized to edit this comment"}
     * @response 404 {"success": false, "message": "Comment not found"}
     * @response 422 {"success": false, "message": "Validation failed"}
     * @response 500 {"success": false, "message": "Failed to update comment"}
     */
    public function update(Request $request, $id)
    {
        try {
            $comment = Comment::findOrFail($id);

            $user = AuthHelper::getUserFromBearerToken($request);

            // Check if user can edit this comment (owner or admin)
            if ($comment->user_id !== $user->id && ! $user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to edit this comment',
                ], 403);
            }

            $validated = $request->validate([
                'body' => 'required|string|max:1000',
            ]);

            $comment->update([
                'body' => $validated['body'],
            ]);

            $comment->load(['user', 'task']);

            return response()->json([
                'success' => true,
                'message' => 'Comment updated successfully',
                'data' => $comment,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found',
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
                'message' => 'Failed to update comment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete Comment
     *
     * Permanently delete a comment. Only the comment author or an admin can delete a comment.
     *
     * @urlParam id integer required The comment ID. Example: 1
     *
     * @response 200 {"success": true, "message": "Comment deleted successfully"}
     * @response 403 {"success": false, "message": "Unauthorized to delete this comment"}
     * @response 404 {"success": false, "message": "Comment not found"}
     * @response 500 {"success": false, "message": "Failed to delete comment"}
     */
    public function destroy(Request $request, $id)
    {
        try {
            $comment = Comment::findOrFail($id);

            $user = AuthHelper::getUserFromBearerToken($request);

            // Check if user can delete this comment (owner or admin)
            if ($comment->user_id !== $user->id && ! $user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this comment',
                ], 403);
            }

            $comment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Comment deleted successfully',
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete comment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
