<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupervisorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int The supervisor ID. Example: 1 */
            'id' => $this->id,
            /** @var int The user ID. Example: 3 */
            'user_id' => $this->user_id,
            /** @var int The specialty ID. Example: 1 */
            'specialty_id' => $this->specialty_id,
            /** @var UserResource The user details */
            'user' => $this->whenLoaded('user', fn() => new UserResource($this->user)),
        ];
    }
}
