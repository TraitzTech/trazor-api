<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @tags Announcements
 */
class AnnouncementController extends Controller
{
    /**
     * List All Announcements
     *
     * Retrieve all announcements in the system, ordered by most recent first.
     * Each announcement includes its author information.
     *
     */
    public function index()
    {
        return response()->json([
            'announcements' => Announcement::with('author')->latest()->get(),
        ]);
    }

    /**
     * Show Announcement
     *
     * Retrieve a specific announcement by its ID with author details.
     *
     * @urlParam id integer required The announcement ID. Example: 1
     *
     */
    public function show($id)
    {
        $announcement = Announcement::with('author')->findOrFail($id);

        return response()->json([
            'announcement' => $announcement,
        ]);
    }

    /**
     * Create Announcement
     *
     * Create a new announcement and automatically send push notifications
     * to all matching recipients based on the target audience.
     *
     * **Target options:**
     * - `all` - Send to all users
     * - `intern` - Send to all interns
     * - `supervisor` - Send to all supervisors
     * - `specialty` - Send to users in a specific specialty (requires specialty_id)
     *
     * @bodyParam title string required The announcement title. Example: Important Update
     * @bodyParam content string required The announcement body content. Example: Please read this important message...
     * @bodyParam target string required Target audience. Example: all
     * @bodyParam specialty_id integer Required when target is "specialty". Example: 1
     * @bodyParam priority string Priority level. Example: normal
     *
     */
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

    /**
     * Update Announcement
     *
     * Update an existing announcement. Only the creator or an admin can edit an announcement.
     *
     * @urlParam id integer required The announcement ID. Example: 1
     *
     * @bodyParam title string required The announcement title. Example: Updated Title
     * @bodyParam content string required The announcement content. Example: Updated content...
     * @bodyParam target string required Target audience. Example: all
     * @bodyParam specialty_id integer Required when target is "specialty". Example: 1
     * @bodyParam priority string Priority level. Example: high
     *
     */
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

    /**
     * Delete Announcement
     *
     * Permanently delete an announcement. Only the creator or an admin can delete an announcement.
     *
     * @urlParam id integer required The announcement ID. Example: 1
     *
     */
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

    /**
     * Get Announcements by Creator
     *
     * Retrieve all announcements created by the authenticated user.
     * Useful for managing one's own announcements.
     *
     */
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

    /**
     * Get Announcements for Intern
     *
     * Retrieve announcements relevant to the authenticated intern.
     * Returns announcements targeted to:
     * - All users
     * - Interns specifically
     * - The intern's assigned specialty
     *
     */
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
