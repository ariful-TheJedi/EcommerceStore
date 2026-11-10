<?php

namespace App\Customers\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only([
            'id', 'label', 'recipient_name', 'phone', 'address_line1', 'address_line2',
            'area', 'city', 'district', 'division', 'upazila', 'postal_code',
            'country_code', 'delivery_instructions', 'is_default',
        ]);
    }
}
