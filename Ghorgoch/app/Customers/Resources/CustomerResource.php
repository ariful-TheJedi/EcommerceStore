<?php

namespace App\Customers\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'phone' => $this->user->phone,
            'avatar_path' => $this->user->avatar_path,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'status' => $this->closed_at !== null ? 'closed' : ($this->user->is_active ? 'active' : 'blocked'),
            'email_verified' => $this->user->email_verified_at !== null,
        ];
    }
}
