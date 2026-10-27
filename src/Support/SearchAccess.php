<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Support;

use Dashed\DashedCore\Models\User;

/**
 * Bepaalt welke zoekresultaten een gebruiker te zien krijgt. De providers komen
 * uit andere pakketten en kijken zelf niet naar rechten, dus de grens zit hier:
 * een provider met een eigen ability wordt alleen aangeroepen als de gebruiker
 * dat recht heeft, en zonder ability wordt elk item op zijn type gefilterd.
 */
class SearchAccess
{
    /**
     * Het recht per resultaattype, gelijk aan de routes die het item openen
     * (een klant is /customers, en die vraagt orders.read).
     *
     * @var array<string, string>
     */
    public const TYPE_ABILITIES = [
        'order' => 'orders.read',
        'customer' => 'orders.read',
        'product' => 'products.read',
        'conversation' => 'chat.read',
    ];

    /** Een type dat hier niet in staat valt terug op het algemene leesrecht. */
    public const DEFAULT_ABILITY = 'dashboard.read';

    /** @var array<int, string> */
    private array $abilities;

    public function __construct(private User $user, private string $siteId, AbilityResolver $resolver)
    {
        $this->abilities = $resolver->abilitiesFor($user);
    }

    public function has(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }

    public function allowsType(string $type): bool
    {
        // Gesprekken: chat-rechten gelden per site en per medewerker, net als op
        // de chat-routes zelf (EnsureChatAgentAbility), niet via de rol.
        if ($type === 'conversation' && class_exists(\Dashed\DashedLivechat\Support\ChatAccess::class)) {
            return \Dashed\DashedLivechat\Support\ChatAccess::can($this->user, $this->siteId, self::TYPE_ABILITIES['conversation']);
        }

        return $this->has(self::TYPE_ABILITIES[$type] ?? self::DEFAULT_ABILITY);
    }
}
