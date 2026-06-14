<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Models;

use Dashed\DashedCore\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eén inbox-rij per ontvangende gebruiker voor een verstuurde push. Zo kan de
 * app een notificatie-inbox tonen (gelezen/ongelezen, deep-link) los van de
 * vluchtige OS-push.
 */
class MobileNotification extends Model
{
    protected $table = 'dashed__mobile_notifications';

    protected $guarded = [];

    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
