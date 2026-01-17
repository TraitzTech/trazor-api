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
        return [
            /** @var int The user ID. Example: 1 */
            'id' => $this->id,
            /** @var string The user's full name. Example: John Doe */
            'name' => $this->name,
            /** @var string The user's email address. Example: john@example.com */
            'email' => $this->email,
        ];
    }
}
