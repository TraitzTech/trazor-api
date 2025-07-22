<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Logbook;
use App\Models\LogbookReview;
use Illuminate\Http\Request;

class LogbookReviewController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:approved,needs_revision',
            'feedback' => 'nullable|string',
        ]);

        $user = AuthHelper::getUserFromBearerToken($request);

        $logbook = Logbook::with('intern.user')->findOrFail($id);

        $logbook->status = $request->status;
        $logbook->reviewed_by = $user->id;
        $logbook->reviewed_at = now();
        $logbook->save();

        // Save review record
        $review = LogbookReview::create([
            'logbook_id' => $id,
            'reviewed_by' => $user->id,
            'feedback' => $request->feedback,
            'status' => $request->status,
        ]);

        // Notify the intern who owns the logbook
        if ($logbook->intern && $logbook->intern->user && $logbook->intern->user->device_token) {
            app(NotificationController::class)->sendNotification(new Request([
                'user_id' => $logbook->intern->user->id,
                'title' => 'Logbook Reviewed',
                'body' => 'Your logbook has been reviewed by '.$user->name.'. Status: '.$request->status.'.',
            ]));
        }

        return response()->json(['message' => 'Reviewed successfully', 'review' => $review]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Logbook $logbook)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Logbook $logbook)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Logbook $logbook)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Logbook $logbook)
    {
        //
    }
}
