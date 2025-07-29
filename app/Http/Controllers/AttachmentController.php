<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AttachmentController extends Controller
{
    /**
     * Display a listing of the resource.
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
     * Show the form for creating a new resource.
     */
    public function create(Request $request) {}

    /**
     * Store a newly created resource in storage.
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
                'uploaded_by' => auth()->id(),
            ]);

            $attachment->load(['task', 'uploader']);

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
     * Display the specified resource.
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
     * Download the specified resource.
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
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $attachment = Attachment::findOrFail($id);

            // Check if user can edit this attachment (uploader or admin)
            if ($attachment->uploaded_by !== auth()->id() && ! auth()->user()->hasRole('admin')) {
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
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $attachment = Attachment::findOrFail($id);

            // Check if user can delete this attachment (uploader or admin)
            if ($attachment->uploaded_by !== auth()->id() && ! auth()->user()->hasRole('admin')) {
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
