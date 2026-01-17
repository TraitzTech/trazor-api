<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskWithProgressResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'due_date' => $this->due_date,
            'status' => $this->status,
            'specialty' => $this->whenLoaded('specialty', fn() => new SpecialtyResource($this->specialty)),
            'assigner' => $this->whenLoaded('assigner', fn() => new UserResource($this->assigner)),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'progress' => method_exists($this, 'getProgressSummary') ? $this->getProgressSummary() : null,
            'interns' => $this->whenLoaded('internsWithStatus', function () {
                return $this->internsWithStatus->map(function ($intern) {
                    return [
                        'id' => $intern->id,
                        'user' => $intern->relationLoaded('user') ? new UserResource($intern->user) : null,
                        'status' => $intern->pivot->status ?? null,
                        'started_at' => $intern->pivot->started_at ?? null,
                        'completed_at' => $intern->pivot->completed_at ?? null,
                        'intern_notes' => $intern->pivot->intern_notes ?? null,
                    ];
                });
            }),
            'attachments' => $this->whenLoaded('attachments', fn() => AttachmentResource::collection($this->attachments)),
            'comments' => $this->whenLoaded('comments', function () {
                return $this->comments->map(function ($comment) {
                    return [
                        'id' => $comment->id,
                        'content' => $comment->content,
                        'user' => $comment->relationLoaded('user') ? new UserResource($comment->user) : null,
                        'created_at' => $comment->created_at,
                    ];
                });
            }),
        ];
    }
}
