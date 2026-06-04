<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $token = $this->currentAccessToken();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'roles' => $this->roles->pluck('name')->values(),
            'abilities' => $token ? array_values($token->abilities ?? []) : [],
        ];
    }
}
