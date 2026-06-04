<?php

declare(strict_types=1);

namespace Dashed\DashedMobileApi\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Dashed\DashedCore\Classes\Sites;
use Dashed\DashedLivechat\Models\ChatConversation;
use Dashed\DashedLivechat\Services\HandoffService;
use Dashed\DashedLivechat\Services\ConversationManager;
use Dashed\DashedMobileApi\Http\Resources\MessageResource;
use Dashed\DashedMobileApi\Http\Resources\ConversationResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ChatConversation::query()->where('site_id', Sites::getActive());

        if ($mode = $request->query('mode')) {
            $query->where('mode', (string) $mode);
        }

        if ($status = $request->query('status')) {
            $query->where('status', (string) $status);
        }

        $perPage = (int) config('dashed-mobile-api.default_page_size', 25);

        return ConversationResource::collection(
            $query->orderByDesc('last_message_at')->paginate($perPage),
        );
    }

    public function messages(Request $request, int $conversation): AnonymousResourceCollection
    {
        $model = $this->resolve($conversation);

        $query = $model->messages()->where('is_internal', false);

        if ($afterId = $request->query('after_id')) {
            $query->where('id', '>', (int) $afterId);
        }

        $perPage = (int) config('dashed-mobile-api.default_page_size', 25);

        return MessageResource::collection($query->limit($perPage)->get());
    }

    public function sendMessage(Request $request, ConversationManager $manager, int $conversation): JsonResponse
    {
        $model = $this->resolve($conversation);

        $data = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $agent = app(HandoffService::class)->humanAgentForUser($request->user(), Sites::getActive());
        $message = $manager->addHumanMessage($model, $agent, $data['content']);

        return (new MessageResource($message))
            ->response()
            ->setStatusCode(201);
    }

    public function takeOver(Request $request, HandoffService $handoff, int $conversation): ConversationResource
    {
        $model = $this->resolve($conversation);

        $agent = $handoff->humanAgentForUser($request->user(), Sites::getActive());
        $handoff->takeOver($model, $agent);

        activity()
            ->performedOn($model)
            ->causedBy($request->user())
            ->log('mobile-api: gesprek overgenomen');

        return new ConversationResource($model->fresh());
    }

    public function release(Request $request, HandoffService $handoff, int $conversation): ConversationResource
    {
        $model = $this->resolve($conversation);

        $handoff->release($model);

        activity()
            ->performedOn($model)
            ->causedBy($request->user())
            ->log('mobile-api: gesprek vrijgegeven');

        return new ConversationResource($model->fresh());
    }

    private function resolve(int $conversation): ChatConversation
    {
        return ChatConversation::query()
            ->where('site_id', Sites::getActive())
            ->findOrFail($conversation);
    }
}
