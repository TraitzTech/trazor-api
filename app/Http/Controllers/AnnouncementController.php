<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function index()
    {
        return response()->json([
            'announcements' => Announcement::with('author')->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $user = AuthHelper::getUserFromBearerToken($request);
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

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
        } elseif ($validated['target'] !== 'all') {
            $query->role($validated['target']);
        }

        $recipients = $query->whereNotNull('device_token')->get();

        foreach ($recipients as $recipient) {
            app(NotificationController::class)->sendNotification(new Request([
                'user_id' => $recipient->id,
                'title' => $validated['title'],
                'body' => $validated['content'],
            ]));
        }

        return response()->json([
            'message' => 'Announcement created and notifications sent',
            'data' => $announcement,
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
