<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'due_date' => $this->due_date,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'specialty' => $this->whenLoaded('specialty', fn() => new SpecialtyResource($this->specialty)),
            'interns' => $this->whenLoaded('interns', function () {
                return $this->interns->map(function ($intern) {
                    $data = [
                        'id' => $intern->id,
                        'user_id' => $intern->user_id,
                        'institution' => $intern->institution,
                        'matric_number' => $intern->matric_number,
                        'hort_number' => $intern->hort_number,
                        'user' => $intern->relationLoaded('user') ? new UserResource($intern->user) : null,
                        'specialty' => $intern->relationLoaded('specialty') ? new SpecialtyResource($intern->specialty) : null,
                    ];

                    // Include pivot data (individual task submission info) if available
                    if ($intern->pivot) {
                        $data['submission'] = [
                            'status' => $intern->pivot->status ?? 'pending',
                            'started_at' => $intern->pivot->started_at,
                            'completed_at' => $intern->pivot->completed_at,
                            'intern_notes' => $intern->pivot->intern_notes,
                            'assigned_at' => $intern->pivot->created_at,
                            'updated_at' => $intern->pivot->updated_at,
                        ];
                    }

                    return $data;
                });
            }),
            'comments' => $this->whenLoaded('comments', fn() => CommentResource::collection($this->comments)),
            'attachments' => $this->whenLoaded('attachments', fn() => AttachmentResource::collection($this->attachments)),
        ];
    }
}
