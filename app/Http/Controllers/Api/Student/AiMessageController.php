<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Student\StoreAiMessageRequest;
use App\Http\Resources\Api\Student\AiMessageResource;
use App\Models\AiMessage;
use App\Services\AiChat\AiChatService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiMessageController extends Controller
{
    use ApiResponse;

    public function __construct(private AiChatService $chat) {}

    public function index(Request $request, string $conversation): JsonResponse
    {
        $record = $this->chat->ownedConversation($request->user(), $conversation);
        $messages = $record->messages()->with('attachments')->orderByDesc('id')->paginate(min(max($request->integer('per_page', 50), 1), 100));

        return $this->paginatedResponse(AiMessageResource::collection($messages));
    }

    public function store(StoreAiMessageRequest $request, string $conversation): JsonResponse
    {
        $result = $this->chat->submit($request->user(), $conversation, $request->validated('content'), $request->validated('client_message_id'), $request->validated('attachments', []));
        $data = ['user_message' => new AiMessageResource($result['user_message']), 'assistant_message' => new AiMessageResource($result['assistant_message'])];
        if (! $result['scheduled']) {
            return response()->json(['success' => false, 'message' => 'Chat processing could not be scheduled.', 'code' => 'SCHEDULING_FAILED', 'data' => $data, 'errors' => []], 503);
        }

        return $this->successResponse($data, $result['created'] ? 'Message accepted.' : 'Message already received.', $result['created'] ? 202 : 200);
    }

    public function show(Request $request, string $message): JsonResponse
    {
        $record = AiMessage::query()->with('attachments')->whereHas('conversation', fn ($query) => $query->where('user_id', $request->user()->id))->findOrFail($message);

        return $this->successResponse(new AiMessageResource($record));
    }
}
