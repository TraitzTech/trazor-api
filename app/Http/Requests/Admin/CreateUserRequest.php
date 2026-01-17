<?php

namespace App\Http\Requests\Admin;

use App\Helpers\AuthHelper;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @bodyParam name string required The user's full name. Example: John Doe
 * @bodyParam email string required The user's email address. Example: john@example.com
 * @bodyParam role string required The user's role (intern, supervisor, or admin). Example: intern
 * @bodyParam phone string The user's phone number. Example: +1234567890
 * @bodyParam location string The user's location. Example: New York, USA
 * @bodyParam bio string A short bio about the user. Example: Software developer intern
 * @bodyParam specialty_id integer Required for intern and supervisor roles. The specialty ID. Example: 1
 * @bodyParam institution string Required for intern role. The institution name. Example: MIT
 * @bodyParam hort_number string Required for intern role. The HORT number. Example: H001
 * @bodyParam start_date string Required for intern role. The internship start date. Example: 2026-01-20
 * @bodyParam end_date string Required for intern role. The internship end date. Example: 2026-07-20
 * @bodyParam permissions array Required for admin role. Admin permissions. Example: ["user_management", "analytics"]
 */
class CreateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = AuthHelper::getUserFromBearerToken($this);
        return $user && $user->hasRole('admin');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Common fields
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'role' => 'required|in:intern,supervisor,admin',
            'phone' => 'nullable|string|max:20',
            'location' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:500',

            // Intern & Supervisor specific fields
            'specialty_id' => 'required_if:role,intern,supervisor|exists:specialties,id',

            // Intern specific fields
            'institution' => 'required_if:role,intern|string|max:255',
            'hort_number' => 'required_if:role,intern|string|max:10',
            'start_date' => 'required_if:role,intern|date|after_or_equal:today',
            'end_date' => 'required_if:role,intern|date|after:start_date',

            // Admin specific fields
            'permissions' => 'required_if:role,admin|array',
            'permissions.*' => 'string|in:user_management,content_moderation,analytics,system_settings',
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'specialty_id' => 'specialty',
            'hort_number' => 'HORT number',
            'start_date' => 'start date',
            'end_date' => 'end date',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'specialty_id.required_if' => 'Specialty is required for interns and supervisors.',
            'institution.required_if' => 'Institution is required for interns.',
            'hort_number.required_if' => 'HORT number is required for interns.',
            'start_date.required_if' => 'Start date is required for interns.',
            'end_date.required_if' => 'End date is required for interns.',
            'end_date.after' => 'End date must be after the start date.',
            'permissions.required_if' => 'Permissions are required for admins.',
        ];
    }
}
