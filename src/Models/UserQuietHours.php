<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Models;

use Illuminate\Database\Eloquent\Model;

class UserQuietHours extends Model
{
    protected $table = 'dashed__user_quiet_hours';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
