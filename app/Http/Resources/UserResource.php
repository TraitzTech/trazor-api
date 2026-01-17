<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            /** @var string The user ID. Example: 550e8400-e29b-41d4-a716-446655440000 */
            'id' => $this->id,
            /** @var string The user's full name. Example: John Doe */
            'name' => $this->name,
            /** @var string The user's email address. Example: john@example.com */
            'email' => $this->email,
            /** @var string|null The user's location. Example: Lagos, Nigeria */
            'location' => $this->location,
            /** @var string|null The user's phone number. Example: +234 812 345 6789 */
            'phone' => $this->phone,
            /** @var string|null URL to the user's avatar image. Example: https://example.com/avatars/user1.jpg */
            'avatar' => $this->avatar,
            /** @var string|null The user's biography. Example: Software engineer with 5 years experience */
            'bio' => $this->bio,
            /** @var bool Whether the user account is active. Example: true */
            'is_active' => (bool) $this->is_active,
            /** @var string|null The timestamp of the user's last login. Example: 2025-01-17T10:30:00.000000Z */
            'last_login' => $this->last_login,
            /** @var string The timestamp when the user account was created. Example: 2025-01-10T09:15:00.000000Z */
            'created_at' => $this->created_at,
            /** @var string The timestamp when the user account was last updated. Example: 2025-01-17T10:30:00.000000Z */
            'updated_at' => $this->updated_at,
        ];

        // Include intern details if the user has an intern role and the relationship is loaded
        if ($this->relationLoaded('intern')) {
            $data['intern'] = $this->whenLoaded('intern', function () {
                return [
                    'id' => $this->intern->id,
                    'institution' => $this->intern->institution,
                    'matric_number' => $this->intern->matric_number,
                    'hort_number' => $this->intern->hort_number,
                    'start_date' => $this->intern->start_date,
                    'end_date' => $this->intern->end_date,
                    'specialty' => $this->intern->relationLoaded('specialty') ? new \App\Http\Resources\SpecialtyResource($this->intern->specialty) : null,
                ];
            });
        }

        return $data;
    }
}
