<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Models;

use Dashed\DashedCore\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    protected $table = 'dashed__device_tokens';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
