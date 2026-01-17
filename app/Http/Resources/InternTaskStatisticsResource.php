<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Intern task statistics resource.
 *
 * Represents aggregated task progress metrics for a single intern.
 */
class InternTaskStatisticsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int Total number of tasks assigned to the intern. Example: 10 */
            'total_tasks' => $this->resource['total_tasks'] ?? 0,
            /** @var int Number of tasks in pending status. Example: 3 */
            'pending' => $this->resource['pending'] ?? 0,
            /** @var int Number of tasks in progress. Example: 4 */
            'in_progress' => $this->resource['in_progress'] ?? 0,
            /** @var int Number of completed tasks. Example: 3 */
            'completed' => $this->resource['completed'] ?? 0,
            /** @var float Completion rate in percentage. Example: 30.5 */
            'completion_rate' => $this->resource['completion_rate'] ?? 0.0,
        ];
    }
}
