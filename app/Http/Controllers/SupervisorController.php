<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Intern;
use App\Models\Supervisor;
use Illuminate\Http\Request;

class SupervisorController extends Controller
{
    /**
     * Get all interns with the same specialty as a given supervisor.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInternsBySupervisorSpecialty(Request $request)
    {
        $supervisor = AuthHelper::getUserFromBearerToken($request)?->supervisor;

        if (! $supervisor) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Eager load the specialty for the supervisor to avoid N+1 query problem
        $supervisor?->load('specialty');

        // Check if the supervisor has a specialty assigned
        if (! $supervisor?->specialty) {
            return response()->json(['message' => 'Supervisor does not have a specialty assigned.'], 404);
        }

        // Retrieve interns who have the same specialty_id as the supervisor
        // Eager load the 'user' and 'specialty' relationships for interns for complete data
        $interns = Intern::where('specialty_id', $supervisor->specialty->id)
            ->with(['user', 'specialty'])
            ->get();

        if ($interns->isEmpty()) {
            return response()->json(['message' => 'No interns found with the same specialty as this supervisor.'], 404);
        }

        return response()->json(['interns' => $interns], 200);
    }
}
