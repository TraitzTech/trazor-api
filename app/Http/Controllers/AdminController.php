<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdminRequest;
use App\Http\Requests\UpdateAdminRequest;
use App\Models\Admin;
use App\Models\Intern;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function getAllInterns(Request $request)
    {
        $interns = Intern::with(['user', 'specialty'])
            ->get()
            ->map(function ($intern) {
                return [
                    'id' => $intern->id,
                    'user_id' => $intern->user->id,
                    'name' => $intern->user->name,
                    'email' => $intern->user->email,
                    'status' => $intern->user->is_active ? 'active' : 'inactive',
                    'joinDate' => $intern->user->created_at->toDateString(),
                    'avatar' => $intern->user->avatar ?? '/placeholder-avatar.jpg',
                    'location' => $intern->user->location ?? 'Unknown',
                    'institution' => $intern->institution ?? 'N/A',
                    'matricNumber' => $intern->matric_number ?? 'N/A',
                    'hortNumber' => $intern->hort_number,
                    'startDate' => $intern->start_date,
                    'endDate' => $intern->end_date,
                    'specialty' => optional($intern->specialty)->name ?? 'Unassigned',
                    'supervisors' => $intern->specialty ? $intern->specialty->supervisors->map(function ($supervisor) {
                        return [
                            'id' => $supervisor->id,
                            'name' => $supervisor->user->name,
                            'email' => $supervisor->user->email,
                        ];
                    })->toArray() : [],
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $interns,
        ]);
    }

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
    public function store(StoreAdminRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Admin $admin)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Admin $admin)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAdminRequest $request, Admin $admin)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Admin $admin)
    {
        //
    }
}
