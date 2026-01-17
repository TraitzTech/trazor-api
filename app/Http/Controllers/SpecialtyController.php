<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Intern;
use App\Models\Specialty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @tags Specialties
 */
class SpecialtyController extends Controller
{
    /**
     * Create Specialty
     *
     * Create a new specialty/department in the system.
     * Specialties are used to organize interns and supervisors.
     *
     * @bodyParam name string required Unique specialty name. Example: Software Development
     * @bodyParam category string required Specialty category. Example: Technology
     * @bodyParam status string required Status of the specialty. Example: active
     * @bodyParam description string Optional description. Example: Web and mobile application development
     * @bodyParam requirements string Optional requirements. Example: Basic programming knowledge required
     * @bodyParam skills array Optional array of relevant skills. Example: ["JavaScript", "PHP", "Laravel"]
     * @bodyParam partner_companies array Optional array of partner companies. Example: ["Company A", "Company B"]
     *
     * @response 201 {
     *   "message": "Specialty created successfully.",
     *   "data": {
     *     "id": 1,
     *     "name": "Software Development",
     *     "category": "Technology",
     *     "status": "active"
     *   }
     * }
     * @response 422 {"message": "Invalid input", "errors": {"name": ["The name has already been taken."]}}
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:specialties,name',
            'category' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
            'description' => 'nullable|string',
            'requirements' => 'nullable|string',
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:255',
            'partner_companies' => 'nullable|array',
            'partner_companies.*' => 'string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid input',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $specialty = Specialty::create($validated);

        return response()->json([
            'message' => 'Specialty created successfully.',
            'data' => $specialty,
        ], 201);
    }

    /**
     * List All Specialties
     *
     * Retrieve all specialties with their associated interns and supervisors.
     *
     * @response 200 {
     *   "message": "Specialties retrieved successfully.",
     *   "data": [
     *     {
     *       "id": 1,
     *       "name": "Software Development",
     *       "category": "Technology",
     *       "status": "active",
     *       "interns": [{"id": 1, "user": {"name": "John"}}],
     *       "supervisors": [{"id": 1, "user": {"name": "Jane"}}]
     *     }
     *   ]
     * }
     * @response 500 {"message": "An error occurred while retrieving specialties."}
     */
    public function index()
    {
        try {
            $specialties = Specialty::with(['interns.user', 'supervisors.user'])->get();

            return response()->json([
                'message' => 'Specialties retrieved successfully.',
                'data' => $specialties,
            ], 200);
        } catch (\Throwable $th) {
            \Log::error('Error retrieving specialties: '.$th->getMessage());

            return response()->json([
                'message' => 'An error occurred while retrieving specialties.',
                'error' => $th->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Specialty Details
     *
     * Retrieve details of a specific specialty including its interns and supervisors.
     *
     * @urlParam id integer required The ID of the specialty. Example: 1
     * @response 200 {
     *   "message": "Specialty retrieved successfully.",
     *   "data": {
     *     "id": 1,
     *     "name": "Software Development",
     *     "category": "Technology",
     *     "status": "active",
     *     "description": "Web and mobile development",
     *     "interns": [],
     *     "supervisors": []
     *   }
     * }
     * @response 404 {"message": "Specialty not found."}
     */
    public function show($id)
    {
        $specialty = Specialty::with(['interns.user', 'supervisors.user'])->find($id);

        if (! $specialty) {
            return response()->json([
                'message' => 'Specialty not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'Specialty retrieved successfully.',
            'data' => $specialty,
        ], 200);
    }

    /**
     * Update Specialty
     *
     * Update an existing specialty's details.
     *
     * @urlParam id integer required The ID of the specialty to update. Example: 1
     * @bodyParam name string required The specialty name. Must be unique except for current specialty. Example: Software Development
     * @bodyParam category string required The category of the specialty. Example: Technology
     * @bodyParam status string required The status (active or inactive). Example: active
     * @bodyParam description string optional A description of the specialty. Example: Web and mobile app development
     * @bodyParam requirements string optional Prerequisites for the specialty. Example: Basic programming knowledge
     * @bodyParam skills array optional List of skills taught. Example: ["PHP", "Laravel", "Vue.js"]
     * @bodyParam partner_companies array optional Companies partnering with this specialty. Example: ["Tech Corp", "DevHub"]
     * @response 200 {
     *   "message": "Specialty updated successfully.",
     *   "data": {
     *     "id": 1,
     *     "name": "Software Development",
     *     "category": "Technology",
     *     "status": "active"
     *   }
     * }
     * @response 404 {"message": "Specialty not found."}
     * @response 422 {"message": "Invalid input", "errors": {"name": ["The name has already been taken."]}}
     */
    // New update method to handle editing a specialty
    public function update(Request $request, $id)
    {
        $specialty = Specialty::find($id);

        if (! $specialty) {
            return response()->json([
                'message' => 'Specialty not found.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            // Allow same name for this specialty by ignoring unique on this ID
            'name' => 'required|string|max:255|unique:specialties,name,'.$id,
            'category' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
            'description' => 'nullable|string',
            'requirements' => 'nullable|string',
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:255',
            'partner_companies' => 'nullable|array',
            'partner_companies.*' => 'string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid input',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $specialty->update($validated);

        return response()->json([
            'message' => 'Specialty updated successfully.',
            'data' => $specialty,
        ], 200);
    }

    /**
     * Get My Specialty
     *
     * Retrieve the specialty associated with the authenticated user (intern or supervisor).
     * Returns the user's role and their complete specialty details including all interns and supervisors in that specialty.
     *
     * @response 200 scenario="Intern User" {
     *   "role": "intern",
     *   "specialty": {
     *     "id": 1,
     *     "name": "Software Development",
     *     "category": "Technology",
     *     "status": "active",
     *     "description": "Web and mobile application development",
     *     "requirements": "Basic programming knowledge required",
     *     "skills": ["JavaScript", "PHP", "Laravel"],
     *     "partner_companies": ["Company A", "Company B"],
     *     "created_at": "2025-01-15T10:00:00.000000Z",
     *     "updated_at": "2025-01-15T10:00:00.000000Z",
     *     "interns": [
     *       {
     *         "id": 1,
     *         "user_id": 5,
     *         "specialty_id": 1,
     *         "user": {"id": 5, "name": "John Doe", "email": "john@example.com"}
     *       }
     *     ],
     *     "supervisors": [
     *       {
     *         "id": 1,
     *         "user_id": 3,
     *         "specialty_id": 1,
     *         "user": {"id": 3, "name": "Jane Smith", "email": "jane@example.com"}
     *       }
     *     ]
     *   }
     * }
     * @response 200 scenario="Supervisor User" {
     *   "role": "supervisor",
     *   "specialty": {
     *     "id": 1,
     *     "name": "Software Development",
     *     "category": "Technology",
     *     "status": "active",
     *     "description": "Web and mobile application development",
     *     "requirements": "Basic programming knowledge required",
     *     "skills": ["JavaScript", "PHP", "Laravel"],
     *     "partner_companies": ["Company A", "Company B"],
     *     "created_at": "2025-01-15T10:00:00.000000Z",
     *     "updated_at": "2025-01-15T10:00:00.000000Z",
     *     "interns": [],
     *     "supervisors": []
     *   }
     * }
     * @response 401 {"message": "User not authenticated."}
     * @response 404 {"message": "No associated specialty found for this user."}
     */
    public function mySpecialty(Request $request)
    {
        $user = AuthHelper::getUserFromBearerToken($request);

        \Log::info('Auth user:', [$user]);

        if (! $user) {
            return response()->json([
                'message' => 'User not authenticated.',
            ], 401);
        }

        // Check if user is an intern
        if ($user->hasRole('intern')) {
            $specialty = Specialty::with(['interns.user', 'supervisors.user'])
                ->find($user->intern->specialty_id);

            if ($specialty) {
                return response()->json([
                    'role' => 'intern',
                    'specialty' => $specialty,
                ], 200);
            }
        }

        // Check if user is a supervisor
        if ($user->hasRole('supervisor')) {
            $specialty = Specialty::with(['interns.user', 'supervisors.user'])
                ->find($user->supervisor->specialty_id);

            if ($specialty) {
                return response()->json([
                    'role' => 'supervisor',
                    'specialty' => $specialty,
                ], 200);
            }
        }

        return response()->json([
            'message' => 'No associated specialty found for this user.',
        ], 404);
    }

    /**
     * Delete Specialty
     *
     * Permanently remove a specialty from the system.
     *
     * @urlParam id integer required The ID of the specialty to delete. Example: 1
     * @response 200 {"message": "Specialty deleted successfully."}
     * @response 404 {"message": "Specialty not found."}
     */
    public function destroy($id)
    {
        $specialty = Specialty::find($id);

        if (! $specialty) {
            return response()->json(['message' => 'Specialty not found.'], 404);
        }

        $specialty->delete();

        return response()->json(['message' => 'Specialty deleted successfully.'], 200);
    }
}
