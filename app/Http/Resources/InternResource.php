<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InternResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int The intern ID. Example: 1 */
            'id' => $this->id,
            /** @var int The user ID. Example: 5 */
            'user_id' => $this->user_id,
            /** @var int The specialty ID. Example: 1 */
            'specialty_id' => $this->specialty_id,
            /** @var SpecialtyResource|null The intern's specialty details */
            'specialty' => $this->whenLoaded('specialty', fn() => new SpecialtyResource($this->specialty)),
            /** @var UserResource The user details */
            'user' => $this->whenLoaded('user', fn() => new UserResource($this->user)),
        ];
    }
}
