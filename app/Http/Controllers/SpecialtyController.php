<?php

namespace App\Http\Controllers;

use App\Helpers\AuthHelper;
use App\Models\Intern;
use App\Models\Specialty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SpecialtyController extends Controller
{
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

    public function index()
    {
        $specialties = Specialty::with(['interns.user', 'supervisors.user'])->get();

        return response()->json([
            'message' => 'Specialties retrieved successfully.',
            'specialties' => $specialties,
            'totalInterns' => Intern::count(),
        ], 200);
    }

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
