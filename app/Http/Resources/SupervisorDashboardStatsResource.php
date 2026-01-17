<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupervisorDashboardStatsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = is_array($this->resource) ? $this->resource : (array) $this->resource;
        return [
            'interns_count' => $data['interns_count'] ?? 0,
            'tasks_count' => $data['tasks_count'] ?? 0,
            'pending_tasks' => $data['pending_tasks'] ?? 0,
            'in_progress_tasks' => $data['in_progress_tasks'] ?? 0,
            'completed_tasks' => $data['completed_tasks'] ?? 0,
            'submissions_count' => $data['submissions_count'] ?? 0,
        ];
    }
}
