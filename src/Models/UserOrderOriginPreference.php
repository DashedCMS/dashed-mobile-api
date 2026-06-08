<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Models;

use Illuminate\Database\Eloquent\Model;

class UserOrderOriginPreference extends Model
{
    protected $table = 'dashed__user_order_origin_preferences';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
