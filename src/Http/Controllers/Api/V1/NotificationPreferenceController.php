<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedMobileApi\MobileApiRegistry;
use Dashed\DashedMobileApi\Models\DeviceToken;
use Dashed\DashedMobileApi\Support\NotificationCenter;
use Dashed\DashedMobileApi\Support\NotificationPreferences;
use Dashed\DashedMobileApi\Support\OrderOriginPreferences;

class NotificationPreferenceController extends Controller
{
    public function __construct(
        private MobileApiRegistry $registry,
        private NotificationPreferences $preferences,
        private OrderOriginPreferences $orderOrigins,
    ) {
    }

    /**
     * De beschikbare notificatietypes + of de ingelogde gebruiker ze ontvangt.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $enabledMap = $this->preferences->all($user);

        $types = [];
        foreach ($this->registry->notificationTypes() as $key => $type) {
            $types[] = [
                'key' => $key,
                'label' => $type['label'] ?? $key,
                'description' => $type['description'] ?? null,
                'group' => $type['group'] ?? 'Algemeen',
                'enabled' => $enabledMap[$key] ?? (bool) ($type['default'] ?? true),
            ];
        }

        return response()->json([
            'data' => $types,
            'order_origins' => $this->orderOrigins->all($user),
        ]);
    }

    /**
     * Werk de voorkeuren bij. Body: { "preferences": { "order.placed": true, ... } }.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['sometimes', 'array'],
            'preferences.*' => ['boolean'],
            'order_origins' => ['sometimes', 'array'],
            'order_origins.*' => ['boolean'],
        ]);

        $user = $request->user();
        foreach (($data['preferences'] ?? []) as $type => $enabled) {
            if ($this->registry->notificationType((string) $type)) {
                $this->preferences->set($user, (string) $type, (bool) $enabled);
            }
        }
        foreach (($data['order_origins'] ?? []) as $origin => $enabled) {
            if ($this->registry->orderOrigin((string) $origin)) {
                $this->orderOrigins->set($user, (string) $origin, (bool) $enabled);
            }
        }

        return $this->index($request);
    }

    /**
     * Stuur een testnotificatie naar de eigen geregistreerde toestellen, zodat
     * de gebruiker kan controleren of push werkt.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();
        $tokens = DeviceToken::where('user_id', $user->id)->pluck('token')->all();

        if (! $tokens) {
            return response()->json(['message' => 'Geen geregistreerd toestel gevonden.'], 422);
        }

        app(NotificationCenter::class)->push()
            ->title('Testnotificatie')
            ->body('Push-notificaties werken op dit toestel. 🎉')
            ->sound('default')
            ->route('/settings')
            ->toTokens($tokens)
            ->send();

        return response()->json(['message' => 'Testnotificatie verstuurd.']);
    }
}
