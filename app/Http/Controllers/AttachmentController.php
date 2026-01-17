<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * @tags Attachments
 */
class AttachmentController extends Controller
{
    /**
     * List Attachments
     *
     * Retrieve a list of all attachments, optionally filtered by task.
     * Each attachment includes its associated task and uploader information.
     *
     * @queryParam task_id integer Filter attachments by task ID. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "data": [
     *     {
     *       "id": 1,
     *       "task_id": 1,
     *       "original_name": "report.pdf",
     *       "file_size": 102400,
     *       "mime_type": "application/pdf",
     *       "description": "Weekly progress report",
     *       "task": {"id": 1, "title": "Complete Report"},
     *       "uploader": {"id": 5, "name": "John Doe"}
     *     }
     *   ]
     * }
     * @response 500 {"success": false, "message": "Failed to retrieve attachments"}
     */
    public function index(Request $request)
    {
        try {
            $query = Attachment::with(['task', 'uploader'])
                ->orderBy('created_at', 'desc');

            // Filter by task if task_id is provided
            if ($request->has('task_id')) {
                $query->where('task_id', $request->task_id);
            }

            $attachments = $query->get();

            return response()->json([
                'success' => true,
                'data' => $attachments,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve attachments',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Notify all task stakeholders about a new attachment
     */
    protected function notifyStakeholders(Task $task, Attachment $attachment, User $uploader)
    {
        try {
            // Get all users to notify (assigned interns + task creator)
            $stakeholders = User::whereHas('intern', function ($query) use ($task) {
                $query->whereIn('id', $task->interns()->pluck('intern_id'));
            })
                ->orWhere('id', $task->assigned_by)
                ->where('id', '!=', $uploader->id) // Don't notify the uploader
                ->get();

            foreach ($stakeholders as $user) {
                // Use your existing notification system
                app(NotificationController::class)->sendNotification(new Request([
                    'user_id' => $user->id,
                    'title' => 'New attachment added',
                    'body' => $uploader->name.' added a new file to task: '.$task->title,
                    'data' => [
                        'type' => 'attachment_uploaded',
                        'task_id' => $task->id,
                        'attachment_id' => $attachment->id,
                    ],
                ]));
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send attachment notifications: '.$e->getMessage());
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request) {}

    /**
     * Upload Attachment
     *
     * Upload a file attachment to a specific task. Automatically notifies
     * all task stakeholders (assigned interns and task creator) about the new file.
     *
     * **Supported file types:** PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, JPG, JPEG, PNG, GIF, ZIP, RAR, TXT
     *
     * **Maximum file size:** 4MB
     *
     * @urlParam taskId integer The task ID (optional if task_id is in body). Example: 1
     *
     * @bodyParam task_id integer Required if taskId not in URL. Example: 1
     * @bodyParam file file required The file to upload (max 4MB).
     * @bodyParam description string Optional description. Example: Weekly progress report
     *
     * @response 201 {
     *   "success": true,
     *   "message": "File uploaded successfully",
     *   "data": {
     *     "id": 1,
     *     "original_name": "report.pdf",
     *     "file_size": 102400,
     *     "mime_type": "application/pdf",
     *     "description": "Weekly progress report"
     *   }
     * }
     * @response 404 {"success": false, "message": "Task not found"}
     * @response 422 {"success": false, "message": "Validation failed", "errors": {}}
     * @response 500 {"success": false, "message": "Failed to upload file"}
     */
    public function store(Request $request, $taskId = null)
    {
        try {
            // Use taskId from URL parameter if provided, otherwise from request
            $task_id = $taskId ?? $request->input('task_id');

            $validated = $request->validate([
                'task_id' => $taskId ? 'nullable' : 'required|exists:tasks,id',
                'file' => 'required|file|max:4096|mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif,zip,rar,txt',
                'description' => 'nullable|string|max:255',
            ]);

            $user = AuthHelper::getUserFromBearerToken($request);

            // If taskId is in URL, use it and validate task exists
            if ($taskId) {
                $task = Task::findOrFail($taskId);
                $task_id = $taskId;
            } else {
                $task = Task::findOrFail($validated['task_id']);
                $task_id = $validated['task_id'];
            }

            $file = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $filename = time().'_'.$originalName;

            $path = $file->storeAs('attachments', $filename, 'public');

            $attachment = $task->attachments()->create([
                'path' => $path,
                'original_name' => $originalName,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'description' => $validated['description'] ?? null,
                'uploaded_by' => $user->id,
            ]);

            $attachment->load(['task', 'uploader']);

            // Notify stakeholders
            $this->notifyStakeholders($task, $attachment, $user);

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully',
                'data' => $attachment,
            ], 201);

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
                'message' => 'Failed to upload file',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show Attachment
     *
     * Retrieve details of a specific attachment including its task and uploader information.
     *
     * @urlParam id integer required The attachment ID. Example: 1
     *
     * @response 200 {
     *   "success": true,
     *   "data": {
     *     "id": 1,
     *     "original_name": "report.pdf",
     *     "file_size": 102400,
     *     "mime_type": "application/pdf",
     *     "task": {"id": 1, "title": "Complete Report"},
     *     "uploader": {"id": 5, "name": "John Doe"}
     *   }
     * }
     * @response 404 {"success": false, "message": "Attachment not found"}
     * @response 500 {"success": false, "message": "Failed to retrieve attachment"}
     */
    public function show($id)
    {
        try {
            $attachment = Attachment::with(['task', 'uploader'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $attachment,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Attachment not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve attachment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download Attachment
     *
     * Download the file associated with an attachment.
     * Returns the file with its original filename.
     *
     * @urlParam id integer required The attachment ID. Example: 1
     *
     * @response 200 Binary file download
     * @response 404 {"success": false, "message": "Attachment not found"}
     * @response 404 {"success": false, "message": "File not found on server"}
     * @response 500 {"success": false, "message": "Failed to download file"}
     */
    public function download($id)
    {
        try {
            $attachment = Attachment::findOrFail($id);

            if (! Storage::disk('public')->exists($attachment->path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'File not found on server',
                ], 404);
            }

            return Storage::disk('public')->download(
                $attachment->path,
                $attachment->original_name ?? basename($attachment->path)
            );

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Attachment not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to download file',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id) {}

    /**
     * Update Attachment
     *
     * Update an attachment's description. Only the uploader or an admin can update an attachment.
     *
     * @urlParam id integer required The attachment ID. Example: 1
     *
     * @bodyParam description string The new description for the attachment. Example: Updated description
     *
     * @response 200 {
     *   "success": true,
     *   "message": "Attachment updated successfully",
     *   "data": {"id": 1, "description": "Updated description"}
     * }
     * @response 403 {"success": false, "message": "Unauthorized to edit this attachment"}
     * @response 404 {"success": false, "message": "Attachment not found"}
     * @response 422 {"success": false, "message": "Validation failed"}
     * @response 500 {"success": false, "message": "Failed to update attachment"}
     */
    public function update(Request $request, $id)
    {
        try {
            $attachment = Attachment::findOrFail($id);

            $user = AuthHelper::getUserFromBearerToken($request);
            // Check if user can edit this attachment (uploader or admin)
            if ($attachment->uploaded_by !== $user->id && ! $user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to edit this attachment',
                ], 403);
            }

            $validated = $request->validate([
                'description' => 'nullable|string|max:255',
            ]);

            $attachment->update([
                'description' => $validated['description'] ?? null,
            ]);

            $attachment->load(['task', 'uploader']);

            return response()->json([
                'success' => true,
                'message' => 'Attachment updated successfully',
                'data' => $attachment,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Attachment not found',
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
                'message' => 'Failed to update attachment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete Attachment
     *
     * Permanently delete an attachment and its associated file from storage.
     * Only the uploader or an admin can delete an attachment.
     *
     * @urlParam id integer required The attachment ID. Example: 1
     *
     * @response 200 {"success": true, "message": "Attachment deleted successfully"}
     * @response 403 {"success": false, "message": "Unauthorized to delete this attachment"}
     * @response 404 {"success": false, "message": "Attachment not found"}
     * @response 500 {"success": false, "message": "Failed to delete attachment"}
     */
    public function destroy(Request $request, $id)
    {
        try {
            $attachment = Attachment::findOrFail($id);

            $user = AuthHelper::getUserFromBearerToken($request);
            // Check if user can delete this attachment (uploader or admin)
            if ($attachment->uploaded_by !== $user->id && ! $user->hasRole('admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this attachment',
                ], 403);
            }

            // Delete the file from storage
            if (Storage::disk('public')->exists($attachment->path)) {
                Storage::disk('public')->delete($attachment->path);
            }

            // Delete the database record
            $attachment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Attachment deleted successfully',
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Attachment not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete attachment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
