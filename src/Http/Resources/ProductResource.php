<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'price' => $this->price !== null ? (float) $this->price : null,
            'stock' => $this->stock !== null ? (int) $this->stock : null,
            'public' => (bool) $this->public,
        ];
    }
}
