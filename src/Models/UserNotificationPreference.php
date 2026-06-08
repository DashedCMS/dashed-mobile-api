<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotificationPreference extends Model
{
    protected $table = 'dashed__user_notification_preferences';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
