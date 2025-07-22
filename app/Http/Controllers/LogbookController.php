<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Logbook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LogbookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Logbook::with(['intern.user', 'intern.specialty', 'reviewer'])->latest()->get());
    }

    /**
     * Display a listing of the resource for the authenticated intern.
     */
    public function internLogbooks(Request $request)
    {
        $user = AuthHelper::getUserFromBearerToken($request);

        if (! $user->hasRole('intern') || ! $user->intern) {
            return response()->json(['message' => 'User is not an intern or has no intern profile.'], 403);
        }

        $logbooks = Logbook::where('intern_id', $user->intern->id)
            ->with(['intern.user', 'intern.specialty', 'reviewer', 'reviews'])
            ->latest()
            ->get();

        return response()->json($logbooks);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = AuthHelper::getUserFromBearerToken($request);

        // Base validation rules
        $rules = [
            'date' => 'required|date',
            'title' => 'required|string',
            'content' => 'required|string',
            'hours_worked' => 'nullable|numeric|min:0|max:24',
            'tasks_completed' => 'nullable|array',
            'challenges' => 'nullable|string',
            'learnings' => 'nullable|string',
            'next_day_plans' => 'nullable|string',
        ];

        // Conditionally add intern_id validation based on user role.
        // This assumes you have a role system, like Spatie's laravel-permission,
        // where you can check roles using $user->hasRole().
        if ($user->hasRole('admin')) {
            $rules['intern_id'] = 'required|exists:interns,id';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid input',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // If the user is an intern, get their ID from the relationship
        if ($user->hasRole('intern')) {
            if (! $user->intern) {
                return response()->json(['message' => 'Authenticated user does not have an intern profile.'], 422);
            }
            $validated['intern_id'] = $user->intern->id;
        }

        $validated['submitted_at'] = now();
        $logbook = Logbook::create($validated);

        // Eager load relationships for notifications and response
        $logbook->load('intern.user', 'intern.specialty');
        $intern = $logbook->intern;

        // Notify the intern who submitted
        if ($intern && $intern->user && $intern->user->device_token) {
            app(NotificationController::class)->sendNotification(new Request([
                'user_id' => $intern->user->id,
                'title' => 'Logbook Submitted',
                'body' => 'Your logbook for '.now()->toFormattedDateString().' has been submitted successfully.',
            ]));
        }

        // Notify supervisors with the same specialty
        if ($intern && $intern->specialty) {
            $supervisors = \App\Models\User::whereHas('supervisor', function ($query) use ($intern) {
                $query->where('specialty_id', $intern->specialty->id);
            })->whereNotNull('device_token')->get();

            foreach ($supervisors as $supervisor) {
                app(NotificationController::class)->sendNotification(new Request([
                    'user_id' => $supervisor->id,
                    'title' => 'New Logbook Entry',
                    'body' => $intern->user->name.' submitted a new logbook for review.',
                ]));
            }
        }

        return response()->json(['message' => 'Created', 'logbook' => $logbook], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $logbook = Logbook::with([
            'intern.user',
            'reviews.reviewer',
            'intern.specialty.supervisors.user',
        ])->findOrFail($id);

        return response()->json($logbook);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $logbook = Logbook::findOrFail($id);
        $logbook->update($request->all());

        return response()->json(['message' => 'Updated', 'logbook' => $logbook]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        Logbook::findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
