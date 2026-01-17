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
        return [
            'interns_count' => $this['interns_count'] ?? 0,
            'tasks_count' => $this['tasks_count'] ?? 0,
            'pending_tasks' => $this['pending_tasks'] ?? 0,
            'in_progress_tasks' => $this['in_progress_tasks'] ?? 0,
            'completed_tasks' => $this['completed_tasks'] ?? 0,
            'submissions_count' => $this['submissions_count'] ?? 0,
        ];
    }
}
