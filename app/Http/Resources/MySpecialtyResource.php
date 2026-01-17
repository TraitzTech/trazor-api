<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MySpecialtyResource extends JsonResource
{
    /**
     * The user's role (intern or supervisor).
     *
     * @var string
     */
    public string $role;

    /**
     * The specialty object.
     *
     * @var \App\Models\Specialty
     */
    public $specialty;

    /**
     * Create a new resource instance.
     *
     * @param string $role
     * @param \App\Models\Specialty $specialty
     */
    public function __construct(string $role, $specialty)
    {
        $this->role = $role;
        $this->specialty = $specialty;
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var string The user's role (intern or supervisor). Example: intern */
            'role' => $this->role,
            /** @var SpecialtyResource The specialty details with interns and supervisors */
            'specialty' => new SpecialtyResource($this->specialty),
        ];
    }
}
