<?php

namespace App\Auth\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'principal_type' => $this->role === 'customer' ? 'customer' : 'staff',
            'email_verified' => $this->email_verified_at !== null,
        ];
    }
}
