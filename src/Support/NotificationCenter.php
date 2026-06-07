<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

/**
 * Centrale ingang om app-notificaties te versturen. Geeft een vloeiende builder
 * terug; zo kan elke plek in de CMS met één regel een notificatie sturen die in
 * de app met het juiste geluid binnenkomt en de juiste pagina opent.
 *
 *   app(NotificationCenter::class)->push()
 *       ->title('Nieuwe bestelling')->body($body)
 *       ->sound('order')->route("/order/{$id}")
 *       ->toAbility('orders.read')->send();
 */
class NotificationCenter
{
    public function __construct(private ExpoPushService $push)
    {
    }

    public function push(): PushNotification
    {
        return new PushNotification($this->push);
    }
}
