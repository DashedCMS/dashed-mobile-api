<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedEcommerceCore\Models\Order;
use Dashed\DashedLivechat\Models\ChatConversation;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $site = Sites::getActive();

        $ordersToday = Order::thisSite()
            ->isPaid()
            ->whereDate('created_at', now()->toDateString())
            ->get();

        $openOrders = Order::thisSite()->unhandled()->count();

        $waitingHuman = ChatConversation::where('site_id', $site)
            ->where('mode', 'waiting_human')
            ->count();

        $openConversations = ChatConversation::where('site_id', $site)
            ->where('status', 'active')
            ->count();

        return response()->json([
            'orders_today_count' => $ordersToday->count(),
            'revenue_today' => round((float) $ordersToday->sum('total'), 2),
            'open_orders' => $openOrders,
            'chat_waiting_human' => $waitingHuman,
            'chat_open' => $openConversations,
        ]);
    }
}
