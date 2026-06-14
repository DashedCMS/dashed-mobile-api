<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedMobileApi\Models\MobileNotification;
use Dashed\DashedMobileApi\Http\Resources\NotificationResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Persisted notificatie-inbox: per ingelogde gebruiker + actieve site de eerder
 * verstuurde push-meldingen, met gelezen/ongelezen-status en deep-link.
 */
class NotificationInboxController extends Controller
{
    /**
     * Nieuwste eerst, gepagineerd. Optionele filters: ?type= en ?unread=1.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->scopedQuery($request)->latest();

        if (($type = $request->query('type')) !== null && $type !== '') {
            $query->where('type', (string) $type);
        }

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $perPage = (int) config('dashed-mobile-api.default_page_size', 25);

        return NotificationResource::collection($query->paginate($perPage));
    }

    /**
     * Aantal ongelezen meldingen voor de huidige gebruiker + actieve site.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->scopedQuery($request)->whereNull('read_at')->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Markeer één melding als gelezen (gescoped op de huidige gebruiker).
     */
    public function read(Request $request, int $id): JsonResponse
    {
        $notification = $this->scopedQuery($request)->findOrFail($id);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return (new NotificationResource($notification))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * Markeer alle meldingen als gelezen voor de huidige gebruiker + actieve site.
     */
    public function readAll(Request $request): JsonResponse
    {
        $this->scopedQuery($request)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['count' => 0]);
    }

    /**
     * Basisquery: alleen de eigen meldingen op de actieve site.
     */
    private function scopedQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return MobileNotification::query()
            ->where('user_id', $request->user()->id)
            ->where('site_id', (string) Sites::getActive());
    }
}
