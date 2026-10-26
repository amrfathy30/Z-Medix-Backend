<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\StoreAiConversationRequest;
use App\Http\Requests\Api\Student\UpdateAiConversationRequest;
use App\Http\Resources\Api\Student\AiConversationResource;
use App\Models\AiConversation;
use App\Services\AiChat\AiChatService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiConversationController extends Controller
{
    use ApiResponse;

    public function __construct(private AiChatService $chat) {}

    public function index(Request $request): JsonResponse
    {
        $conversations = AiConversation::query()->where('user_id', $request->user()->id)
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        return $this->paginatedResponse(AiConversationResource::collection($conversations));
    }

    public function store(StoreAiConversationRequest $request): JsonResponse
    {
        $conversation = AiConversation::query()->create([
            'user_id' => $request->user()->id, 'title' => $request->validated('title', 'New conversation'), 'last_message_at' => now(),
        ]);

        return $this->successResponse(new AiConversationResource($conversation), 'Conversation created.', 201);
    }

    public function show(Request $request, string $conversation): JsonResponse
    {
        return $this->successResponse(new AiConversationResource($this->chat->ownedConversation($request->user(), $conversation)));
    }

    public function update(UpdateAiConversationRequest $request, string $conversation): JsonResponse
    {
        $record = $this->chat->ownedConversation($request->user(), $conversation);
        $record->update($request->validated());

        return $this->successResponse(new AiConversationResource($record));
    }

    public function destroy(Request $request, string $conversation): JsonResponse
    {
        DB::transaction(function () use ($request, $conversation): void {
            AiConversation::query()->where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($conversation)->delete();
        });

        return $this->successResponse(null, 'Conversation deleted.');
    }
}
