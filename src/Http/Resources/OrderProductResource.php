<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'quantity' => (int) $this->quantity,
            'price' => (float) $this->price,
        ];
    }
}
