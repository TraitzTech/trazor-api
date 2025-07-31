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
            'created_at' => $this->created_at,
            'interns' => $this->interns->map(function ($intern) {
                return [
                    'id' => $intern->id,
                    // ... other intern fields
                    'user' => $intern->user ? [
                        'id' => $intern->user->id,
                        'name' => $intern->user->name,
                        'email' => $intern->user->email,
                        'avatar' => $intern->user->avatar,
                    ] : null,
                ];
            }),
            'specialty' => $this->specialty,
            'comments' => $this->comments,
            'attachments' => $this->attachments,
        ];
    }
}
