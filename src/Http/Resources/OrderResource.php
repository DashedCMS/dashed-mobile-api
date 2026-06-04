<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'status' => $this->status,
            'fulfillment_status' => $this->fulfillment_status,
            'total' => $this->total !== null ? (float) $this->total : null,
            'customer_name' => trim((string) ($this->first_name . ' ' . $this->last_name)) ?: $this->email,
            'email' => $this->email,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'products' => OrderProductResource::collection($this->whenLoaded('orderProducts')),
        ];
    }
}
