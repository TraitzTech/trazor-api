<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SpecialtyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int The specialty ID. Example: 1 */
            'id' => $this->id,
            /** @var string The specialty name. Example: Software Development */
            'name' => $this->name,
            /** @var string The category of the specialty. Example: Technology */
            'category' => $this->category,
            /** @var string The status of the specialty (active or inactive). Example: active */
            'status' => $this->status,
            /** @var string|null Description of the specialty. Example: Web and mobile application development */
            'description' => $this->description,
            /** @var string|null Requirements for the specialty. Example: Basic programming knowledge required */
            'requirements' => $this->requirements,
            /** @var array|null List of skills associated with this specialty. Example: ["JavaScript", "PHP", "Laravel"] */
            'skills' => $this->skills,
            /** @var array|null List of partner companies. Example: ["Company A", "Company B"] */
            'partner_companies' => $this->partner_companies,
            /** @var string Created timestamp. Example: 2025-01-15T10:00:00.000000Z */
            'created_at' => $this->created_at,
            /** @var string Updated timestamp. Example: 2025-01-15T10:00:00.000000Z */
            'updated_at' => $this->updated_at,
            /** @var array List of interns in this specialty */
            'interns' => $this->whenLoaded('interns', fn() => InternResource::collection($this->interns)),
            /** @var array List of supervisors in this specialty */
            'supervisors' => $this->whenLoaded('supervisors', fn() => SupervisorResource::collection($this->supervisors)),
        ];
    }
}
