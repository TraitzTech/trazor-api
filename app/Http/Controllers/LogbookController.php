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

class LogbookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return response()->json(Logbook::with(['intern.user', 'intern.specialty', 'reviewer', 'reviews'])->latest()->get());
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
     * Update the specified resource in storage.
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
     * Remove the specified resource from storage.
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
     * Fix existing logbooks with null week numbers
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
     * Manually trigger PDF generation for a completed week
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
     * Debug method to check week completion status
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
