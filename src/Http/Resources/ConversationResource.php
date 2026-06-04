<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'mode' => $this->mode,
            'status' => $this->status,
            'visitor_name' => $this->visitor_name,
            'visitor_email' => $this->visitor_email,
            'locale' => $this->locale,
            'assigned_agent_id' => $this->assigned_agent_id,
            'last_message_at' => optional($this->last_message_at)->toIso8601String(),
        ];
    }
}
