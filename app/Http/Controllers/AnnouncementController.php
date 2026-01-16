<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AnnouncementController extends Controller
{
    public function index()
    {
        return response()->json([
            'announcements' => Announcement::with('author')->latest()->get(),
        ]);
    }

    public function show($id)
    {
        $announcement = Announcement::with('author')->findOrFail($id);

        return response()->json([
            'announcement' => $announcement,
        ]);
    }

    public function store(Request $request)
    {
        $user = AuthHelper::getUserFromBearerToken($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Log the incoming request for debugging
        Log::info('Announcement request data:', $request->all());

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'target' => 'required|in:all,intern,supervisor,specialty',
            'specialty_id' => 'nullable|exists:specialties,id',
            'priority' => 'in:low,normal,high',
        ]);

        $announcement = Announcement::create([
            ...$validated,
            'created_by' => $user->id,
        ]);

        // Send push notification to matching users
        $query = User::query();

        if ($validated['target'] === 'specialty') {
            $query->where(function ($q) use ($validated) {
                $q->whereHas('intern', fn ($q2) => $q2->where('specialty_id', $validated['specialty_id']))
                    ->orWhereHas('supervisor', fn ($q2) => $q2->where('specialty_id', $validated['specialty_id']));
            })->whereHas('roles', fn ($q) => $q->whereIn('name', ['intern', 'supervisor']));
        } elseif ($validated['target'] === 'intern') {
            $query->whereHas('roles', fn ($q) => $q->where('name', 'intern'));
        } elseif ($validated['target'] === 'supervisor') {
            $query->whereHas('roles', fn ($q) => $q->where('name', 'supervisor'));
        }

        $recipients = $query->whereNotNull('device_token')->get();

        Log::info("Found {$recipients->count()} recipients for target: {$validated['target']}");

        $notificationResults = ['sent' => 0, 'failed' => 0, 'duplicates' => 0, 'no_token' => 0];

        if ($recipients->count() > 0) {
            try {
                // Use direct method call with the internal method
                $notificationController = new \App\Http\Controllers\NotificationController;

                $notificationResults = $notificationController->sendBulkNotificationsInternal(
                    $recipients->pluck('id')->toArray(),
                    $validated['title'],
                    $validated['content'],
                    'announcement',
                    $announcement->id
                );

                Log::info('Announcement notification results: ', $notificationResults);
            } catch (\Exception $e) {
                Log::error('Failed to send bulk notifications: '.$e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Announcement created successfully',
            'data' => $announcement->load('author'),
            'recipients_count' => $recipients->count(),
            'notification_results' => $notificationResults,
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = AuthHelper::getUserFromBearerToken($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $announcement = Announcement::findOrFail($id);

        // Check if user is the creator or has admin role
        if ($announcement->created_by !== $user->id && ! $user->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden - You can only edit your own announcements'], 403);
        }

        Log::info('Updating announcement data:', $request->all());

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'target' => 'required|in:all,intern,supervisor,specialty',
            'specialty_id' => 'nullable|exists:specialties,id',
            'priority' => 'in:low,normal,high',
        ]);

        $announcement->update($validated);

        return response()->json([
            'message' => 'Announcement updated successfully',
            'data' => $announcement->load('author'),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = AuthHelper::getUserFromBearerToken($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $announcement = Announcement::findOrFail($id);

        // Check if user is the creator or has admin role
        if ($announcement->created_by !== $user->id && ! $user->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden - You can only delete your own announcements'], 403);
        }

        $announcement->delete();

        Log::info("Announcement {$id} deleted by user {$user->id}");

        return response()->json([
            'message' => 'Announcement deleted successfully',
        ]);
    }

    public function getByCreator(Request $request)
    {
        $user = AuthHelper::getUserFromBearerToken($request);
        $announcements = Announcement::with('author')
            ->where('created_by', $user->id)
            ->latest()
            ->get();

        return response()->json([
            'announcements' => $announcements,
        ]);
    }

    public function getForIntern(Request $request)
    {
        $user = AuthHelper::getUserFromBearerToken($request);

        if (! $user->hasRole('intern')) {
            return response()->json(['message' => 'User is not an intern'], 403);
        }

        $specialtyId = $user->intern->specialty->id;

        $announcements = Announcement::with('author')
            ->where(function ($query) use ($specialtyId) {
                $query->where('target', 'all')
                    ->orWhere('target', 'intern')
                    ->orWhere(function ($q) use ($specialtyId) {
                        $q->where('target', 'specialty')
                            ->where('specialty_id', $specialtyId);
                    });
            })
            ->latest()
            ->get();

        return response()->json([
            'announcements' => $announcements,
        ]);
    }

}
