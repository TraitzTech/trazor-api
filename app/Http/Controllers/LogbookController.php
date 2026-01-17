<?php

namespace App\Http\Controllers;

use App\Helpers\ActivityLogger;
use App\Helpers\AuthHelper;
use App\Models\Logbook;
use App\Models\LogbookReview;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * @tags Logbooks
 */
class LogbookController extends Controller
{
    /**
     * List All Logbooks
     *
     * Retrieve all logbook entries in the system with intern details,
     * specialty information, reviewer data, and reviews.
     *
     * @response 200 [
     *   {
     *     "id": 1,
     *     "title": "Day 1 - Learning the basics",
     *     "content": "Today I learned about...",
     *     "date": "2026-01-15",
     *     "week_number": 1,
     *     "hours_worked": 8,
     *     "status": "pending",
     *     "intern": {"id": 1, "user": {"name": "John Doe"}, "specialty": {"name": "Software Development"}},
     *     "reviews": []
     *   }
     * ]
     */
    public function index()
    {
        return response()->json(Logbook::with(['intern.user', 'intern.specialty', 'reviewer', 'reviews'])->latest()->get());
    }

    /**
     * Get Intern's Logbooks
     *
     * Retrieve all logbook entries for the authenticated intern.
     * Returns entries ordered by most recent first.
     *
     * @response 200 [
     *   {
     *     "id": 1,
     *     "title": "Day 1 - Learning the basics",
     *     "content": "Today I learned about...",
     *     "date": "2026-01-15",
     *     "week_number": 1,
     *     "status": "pending"
     *   }
     * ]
     * @response 403 {"message": "User is not an intern or has no intern profile."}
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

    private function isWeekComplete($internId, $weekNumber)
    {
        $daysFilled = Logbook::where('intern_id', $internId)
            ->where('week_number', $weekNumber)
            ->get()
            ->map(function ($log) {
                $dayName = Carbon::parse($log->date)->format('l'); // Full day name like 'Monday'

                return strtolower($dayName);
            })
            ->unique();

        \Log::info("Days filled for week $weekNumber: ".$daysFilled->implode(', '));

        $requiredDays = collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday']);
        $isComplete = $requiredDays->every(function ($day) use ($daysFilled) {
            return $daysFilled->contains($day);
        });

        \Log::info("Week $weekNumber is complete: ".($isComplete ? 'Yes' : 'No'));

        return $isComplete;
    }

    /**
     * Calculate week number based on internship start date
     */
    private function calculateWeekNumber($date, $startDate)
    {
        if (! $startDate) {
            return 1;
        }

        // Parse dates and ensure they're in the correct timezone
        $logDate = Carbon::parse($date)->startOfDay();
        $start = Carbon::parse($startDate)->startOfDay();

        // If log date is before start date, return week 1
        if ($logDate->lt($start)) {
            return 1;
        }

        // Calculate weeks since start
        $daysDiff = $start->diffInDays($logDate);
        $weekNumber = (int) intval(floor($daysDiff / 7)) + 1;

        \Log::info("Calculate Week - Date: $logDate, Start: $start, Days diff: $daysDiff, Week: $weekNumber");

        return max(1, $weekNumber);
    }

    /**
     * Create Logbook Entry
     *
     * Submit a new daily logbook entry. The week number is automatically calculated
     * based on the intern's start date. When a week is complete (all 5 weekdays filled),
     * a PDF is automatically generated.
     *
     * **Note:** Only one entry per date is allowed. Duplicate entries will be rejected.
     *
     * Admins can create entries on behalf of interns by providing `intern_id`.
     *
     * @bodyParam date date required The date of the logbook entry. Example: 2026-01-15
     * @bodyParam title string required Entry title. Example: Day 1 - Learning the basics
     * @bodyParam content string required Detailed description of work done. Example: Today I learned about the project structure...
     * @bodyParam hours_worked numeric Hours worked (0-24). Example: 8
     * @bodyParam tasks_completed array List of completed tasks. Example: ["Task 1", "Task 2"]
     * @bodyParam challenges string Challenges faced. Example: Had difficulty with...
     * @bodyParam learnings string Key learnings. Example: Learned about dependency injection...
     * @bodyParam next_day_plans string Plans for next day. Example: Will continue working on...
     * @bodyParam intern_id integer Required for admins creating on behalf of intern. Example: 1
     *
     * @response 201 {
     *   "message": "Logbook Created",
     *   "logbook": {"id": 1, "title": "Day 1", "date": "2026-01-15", "week_number": 1},
     *   "week_number": 1,
     *   "pdf_url": "/storage/logbooks/week_1_INT-2026-0001.pdf"
     * }
     * @response 409 {"message": "A logbook entry already exists for this date.", "existing_logbook": {}}
     * @response 422 {"message": "Invalid input", "errors": {}}
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
            $intern = $user->intern;
        } else {
            // Admin creating for an intern
            $intern = \App\Models\Intern::findOrFail($validated['intern_id']);
        }

        $validated['submitted_at'] = now();

        // Use the improved week calculation
        $validated['week_number'] = $this->calculateWeekNumber($validated['date'], $intern->start_date);

        \Log::info("Storing logbook - Date: {$validated['date']}, Week: {$validated['week_number']}, Intern Start: {$intern->start_date}");

        // Check for duplicate entries (same intern, same date)
        $existingLogbook = Logbook::where('intern_id', $validated['intern_id'])
            ->whereDate('date', $validated['date'])
            ->first();

        if ($existingLogbook) {
            return response()->json([
                'message' => 'A logbook entry already exists for this date.',
                'existing_logbook' => $existingLogbook,
            ], 409);
        }

        $logbook = Logbook::create($validated);

        // Eager load relationships for notifications and response
        $logbook->load('intern.user', 'intern.specialty');

        ActivityLogger::log($intern->user->id, 'Logbook filled');

        // Check if week is complete and generate PDF
        \Log::info("Checking week completion for intern {$validated['intern_id']}, week {$validated['week_number']}");

        if ($validated['week_number'] && $this->isWeekComplete($validated['intern_id'], $validated['week_number'])) {
            try {
                $filename = $this->generateWeeklyPdf($validated['intern_id'], $validated['week_number']);
                \Log::info("Weekly PDF generated successfully: $filename");

                // Add PDF URL to response
                $response['pdf_generated'] = true;
                $response['pdf_filename'] = $filename;
            } catch (\Exception $e) {
                \Log::error('PDF generation failed: '.$e->getMessage());
                \Log::error('PDF generation stack trace: '.$e->getTraceAsString());
                // Don't fail the logbook creation if PDF generation fails
                $response['pdf_error'] = $e->getMessage();
            }
        } else {
            \Log::info("Week not complete yet or week number is null. Week: {$validated['week_number']}");
        }

        // Send notifications
        $this->sendNotifications($intern);

        $response = [
            'message' => 'Logbook Created',
            'logbook' => $logbook,
            'week_number' => $validated['week_number'],
        ];

        // Check if PDF exists and add URL
        $filename = 'logbooks/week_'.$validated['week_number'].'_'.$intern->matric_number.'.pdf';
        if (Storage::disk('public')->exists($filename)) {
            $response['pdf_url'] = Storage::url($filename);
        }

        return response()->json($response, 201);
    }

    /**
     * Generate PDF for a complete week
     */
    private function generateWeeklyPdf($internId, $weekNumber)
    {
        $intern = \App\Models\Intern::with('user', 'specialty')->findOrFail($internId);

        $entriesQuery = Logbook::where('intern_id', $internId)
            ->where('week_number', $weekNumber)
            ->orderBy('date')
            ->get();

        if ($entriesQuery->count() < 5) {
            throw new \Exception("Not enough entries for week $weekNumber");
        }

        $entries = $entriesQuery
            ->keyBy(function ($log) {
                return strtolower(Carbon::parse($log->date)->format('l'));
            })
            ->map(function ($log) {
                return $log->content;
            });

        $dates = $entriesQuery->pluck('date')->map(function ($d) {
            return Carbon::parse($d);
        });

        $period_from = $dates->min()->toDateString();
        $period_to = $dates->max()->toDateString();

        $student = [
            'name' => $intern->user->name,
            'matric_number' => $intern->matric_number,
            'level' => $intern->level,
            'department' => $intern->department,
            'option' => $intern->option,
        ];

        $pdf = Pdf::loadView('pdf.logbook', [
            'student' => $student,
            'entries' => $entries,
            'remarks' => '',
            'period_from' => $period_from,
            'period_to' => $period_to,
            'week' => $weekNumber,
        ]);

        $filename = 'logbooks/week_'.$weekNumber.'_'.$student['matric_number'].'.pdf';
        Storage::disk('public')->put($filename, $pdf->output());

        return $filename;
    }

    /**
     * Send notifications to intern and supervisors
     */
    private function sendNotifications($intern)
    {
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
    }

    /**
     * Show Logbook Entry
     *
     * Retrieve detailed information about a specific logbook entry including
     * intern details, reviews, and specialty supervisors.
     *
     * @urlParam id integer required The logbook ID. Example: 1
     *
     * @response 200 {
     *   "id": 1,
     *   "title": "Day 1 - Learning",
     *   "content": "Today I learned...",
     *   "date": "2026-01-15",
     *   "intern": {"user": {"name": "John"}},
     *   "reviews": [{"status": "approved", "feedback": "Good work!"}]
     * }
     * @response 404 {"message": "No query results for model [App\\Models\\Logbook]"}
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
     * Download Week PDF
     *
     * Download the auto-generated PDF for a completed week.
     * Only available for weeks where all 5 weekday entries have been submitted.
     *
     * @urlParam week integer required The week number. Example: 1
     *
     * @response 200 Binary PDF download
     * @response 401 {"message": "Unauthorized."}
     * @response 404 {"message": "PDF not found."}
     */
    public function downloadPdf($week, Request $request)
    {
        $intern = AuthHelper::getUserFromBearerToken($request)->intern;

        if (! $intern) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $filename = 'logbooks/week_'.$week.'_'.$intern->matric_number.'.pdf';

        if (! Storage::disk('public')->exists($filename)) {
            return response()->json(['message' => 'PDF not found.'], 404);
        }

        return response()->download(storage_path('app/public/'.$filename));
    }

    /**
     * Update Logbook Entry
     *
     * Update an existing logbook entry's content.
     *
     * @urlParam id integer required The logbook ID. Example: 1
     *
     * @bodyParam title string The entry title. Example: Updated title
     * @bodyParam content string The entry content. Example: Updated content...
     * @bodyParam hours_worked numeric Hours worked. Example: 7
     * @bodyParam challenges string Challenges faced. Example: Updated challenges...
     * @bodyParam learnings string Key learnings. Example: Updated learnings...
     *
     * @response 200 {
     *   "message": "Logbook updated successfully",
     *   "logbook": {"id": 1, "title": "Updated title"}
     * }
     * @response 404 {"message": "Logbook not found"}
     * @response 422 {"message": "Logbook ID is required"}
     * @response 500 {"message": "An error occurred while updating the logbook"}
     */
    public function update(Request $request, $id)
    {
        if (! $id) {
            return response()->json(['message' => 'Logbook ID is required'], 422);
        }

        try {
            $logbook = Logbook::findOrFail($id);
            $logbook->update($request->all());

            return response()->json([
                'message' => 'Logbook updated successfully',
                'logbook' => $logbook,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Logbook not found'], 404);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'An error occurred while updating the logbook',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete Logbook Entry
     *
     * Permanently delete a logbook entry and all associated reviews.
     *
     * @urlParam id integer required The logbook ID. Example: 1
     *
     * @response 200 {"success": true, "message": "Logbook and all related data deleted successfully"}
     * @response 404 {"success": false, "message": "Logbook not found"}
     * @response 422 {"success": false, "message": "Logbook ID is required"}
     * @response 500 {"success": false, "message": "An error occurred while deleting the logbook"}
     */
    public function destroy($id)
    {
        if (! $id) {
            return response()->json([
                'success' => false,
                'message' => 'Logbook ID is required',
            ], 422);
        }

        try {
            $logbook = Logbook::findOrFail($id);

            DB::beginTransaction();

            // Delete all related logbook reviews
            LogbookReview::where('logbook_id', $logbook->id)->delete();

            // Log the activity before deletion
            if ($logbook->intern && $logbook->intern->user) {
                ActivityLogger::log($logbook->intern->user->id, 'Logbook deleted');
            }

            // Finally, delete the logbook itself
            $logbook->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Logbook and all related data deleted successfully',
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Logbook not found',
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error deleting logbook: '.$e->getMessage(), [
                'logbook_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the logbook',
                'error' => config('app.debug') ? $e->getMessage() : 'Failed to delete logbook',
            ], 500);
        }
    }

    /**
     * Generate Custom PDF
     *
     * Manually generate a logbook PDF with provided data.
     * Useful for custom report generation.
     *
     * @bodyParam student array required Student information object.
     * @bodyParam entries array required Logbook entries array.
     * @bodyParam period_from date required Start date of the period. Example: 2026-01-13
     * @bodyParam period_to date required End date of the period. Example: 2026-01-17
     * @bodyParam week integer required Week number. Example: 1
     *
     * @response 200 {
     *   "message": "Logbook PDF generated",
     *   "url": "/storage/logbooks/week_1_INT-2026-0001.pdf"
     * }
     * @response 422 {"message": "Validation failed"}
     */
    public function generatePdf(Request $request)
    {
        $data = $request->validate([
            'student' => 'required|array',
            'entries' => 'required|array',
            'period_from' => 'required|date',
            'period_to' => 'required|date',
            'week' => 'required|integer',
        ]);

        $pdf = Pdf::loadView('pdf.logbook', [
            'student' => $data['student'],
            'entries' => $data['entries'],
            'remarks' => $data['remarks'] ?? '',
            'period_from' => $data['period_from'],
            'period_to' => $data['period_to'],
            'week' => $data['week'],
        ]);

        $filename = 'logbooks/week_'.$data['week'].'_'.$data['student']['matric_number'].'.pdf';
        Storage::disk('public')->put($filename, $pdf->output());

        return response()->json([
            'message' => 'Logbook PDF generated',
            'url' => Storage::url($filename),
        ]);
    }

    /**
     * Fix Week Numbers
     *
     * Utility endpoint to fix logbook entries with null week numbers.
     * Recalculates week numbers based on intern's start date.
     *
     * @response 200 {
     *   "message": "Fixed 5 logbooks",
     *   "updated_count": 5
     * }
     */
    public function fixWeekNumbers(Request $request)
    {
        $internId = AuthHelper::getUserFromBearerToken($request)->intern->id;

        if ($internId) {
            $intern = \App\Models\Intern::findOrFail($internId);
            $logbooks = Logbook::where('intern_id', $internId)
                ->whereNull('week_number')
                ->get();
        } else {
            $logbooks = Logbook::whereNull('week_number')->get();
        }

        $updated = 0;
        foreach ($logbooks as $logbook) {
            $intern = $logbook->intern;
            $weekNumber = (int) $this->calculateWeekNumber($logbook->date, $intern->start_date);
            $logbook->update(['week_number' => $weekNumber]);
            $updated++;

            \Log::info("Fixed logbook ID {$logbook->id}: Date {$logbook->date} -> Week $weekNumber");
        }

        return response()->json([
            'message' => "Fixed $updated logbooks",
            'updated_count' => $updated,
        ]);
    }

    /**
     * Generate Week PDF Manually
     *
     * Manually trigger PDF generation for a completed week.
     * The week must have all 5 weekday entries to be considered complete.
     *
     * @bodyParam intern_id integer required The intern ID. Example: 1
     * @bodyParam week_number integer required The week number (min 1). Example: 1
     *
     * @response 200 {
     *   "message": "PDF generated successfully",
     *   "filename": "logbooks/week_1_INT-2026-0001.pdf",
     *   "url": "/storage/logbooks/week_1_INT-2026-0001.pdf",
     *   "intern_id": 1,
     *   "week_number": 1
     * }
     * @response 400 {"message": "Week is not complete yet"}
     * @response 422 {"message": "Validation failed", "errors": {}}
     * @response 500 {"message": "PDF generation failed"}
     */
    public function generateWeekPdf(Request $request)
    {
        try {
            $validated = $request->validate([
                'intern_id' => 'required|exists:interns,id',
                'week_number' => 'required|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $internId = AuthHelper::getUserFromBearerToken($request)->intern->id;
        $weekNumber = (int) $validated['week_number'];

        Log::info("Manual PDF generation requested for intern $internId, week $weekNumber");

        if (! $this->isWeekComplete($internId, $weekNumber)) {
            return response()->json([
                'message' => 'Week is not complete yet',
                'intern_id' => $internId,
                'week_number' => $weekNumber,
                'is_complete' => false,
            ], 400);
        }

        try {
            $filename = $this->generateWeeklyPdf($internId, $weekNumber);

            return response()->json([
                'message' => 'PDF generated successfully',
                'filename' => $filename,
                'url' => Storage::url($filename),
                'intern_id' => $internId,
                'week_number' => $weekNumber,
            ]);
        } catch (\Exception $e) {
            Log::error('Manual PDF generation failed: '.$e->getMessage());

            return response()->json([
                'message' => 'PDF generation failed',
                'error' => $e->getMessage(),
                'intern_id' => $internId,
                'week_number' => $weekNumber,
            ], 500);
        }
    }

    /**
     * Check Week Status
     *
     * Debug endpoint to check if a specific week is complete.
     * Returns detailed information about which days have been filled.
     *
     * @bodyParam week_number integer required The week number to check. Example: 1
     *
     * @response 200 {
     *   "intern_id": 1,
     *   "week_number": 1,
     *   "entries_count": 5,
     *   "days_filled": [
     *     {"date": "2026-01-13", "day": "Monday", "day_lower": "monday"}
     *   ],
     *   "is_complete": true
     * }
     */
    public function checkWeekStatus(Request $request)
    {
        $internId = AuthHelper::getUserFromBearerToken($request)->intern->id;
        $weekNumber = (int) $request->input('week_number');

        $entries = Logbook::where('intern_id', $internId)
            ->where('week_number', $weekNumber)
            ->get();

        $daysFilled = $entries->map(function ($log) {
            return [
                'date' => $log->date,
                'day' => Carbon::parse($log->date)->format('l'),
                'day_lower' => strtolower(Carbon::parse($log->date)->format('l')),
            ];
        });

        return response()->json([
            'intern_id' => $internId,
            'week_number' => $weekNumber,
            'entries_count' => $entries->count(),
            'days_filled' => $daysFilled,
            'is_complete' => $this->isWeekComplete($internId, $weekNumber),
        ]);
    }
}
