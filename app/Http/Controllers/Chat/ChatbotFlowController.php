<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\PublishChatbotFlowRequest;
use App\Http\Requests\Chat\SimulateChatbotFlowRequest;
use App\Http\Requests\Chat\StoreChatbotFlowRequest;
use App\Http\Requests\Chat\UpdateChatbotFlowRequest;
use App\Http\Resources\Chat\ChatbotFlowListResource;
use App\Http\Resources\Chat\ChatbotFlowResource;
use App\Http\Resources\Chat\ConversationSessionResource;
use App\Models\Chat\ChatbotFlowVersion;
use App\Models\Chat\ConversationSession;
use App\Services\Chat\ChatbotFlowDefinitionValidator;
use App\Services\Chat\ChatbotFlowPublishService;
use App\Services\Chat\ChatbotFlowService;
use App\Services\Chat\ChatbotFlowSimulationService;
use App\Traits\Chat\ResolvesTenantChatbotUserId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotFlowController extends Controller
{
    use ResolvesTenantChatbotUserId;

    public function __construct(
        private readonly ChatbotFlowService $flowService,
        private readonly ChatbotFlowPublishService $publishService,
        private readonly ChatbotFlowDefinitionValidator $validator,
        private readonly ChatbotFlowSimulationService $simulationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ChatbotFlowVersion::class);

        $paginator = $this->flowService->listForUser($this->tenantUserId(), [
            'group_id' => $request->query('group_id'),
            'status' => $request->query('status'),
            'search' => $request->query('search'),
            'page' => $request->query('page'),
            'per_page' => $request->query('per_page'),
        ]);

        return response()->json([
            'status' => true,
            'data' => ChatbotFlowListResource::collection($paginator)->response()->getData(true),
        ]);
    }

    public function store(StoreChatbotFlowRequest $request): JsonResponse
    {
        $flow = $this->flowService->createDraft($this->tenantUserId(), $request->validated());

        return response()->json([
            'status' => true,
            'data' => new ChatbotFlowResource($flow),
        ], 201);
    }

    public function show(ChatbotFlowVersion $flow): JsonResponse
    {
        $this->authorize('view', $flow);

        return response()->json([
            'status' => true,
            'data' => new ChatbotFlowResource($flow),
        ]);
    }

    public function update(UpdateChatbotFlowRequest $request, ChatbotFlowVersion $flow): JsonResponse
    {
        $updated = $this->flowService->saveDefinition($flow, $request->validated('definition'));

        return response()->json([
            'status' => true,
            'data' => new ChatbotFlowResource($updated),
        ]);
    }

    public function patch(Request $request, ChatbotFlowVersion $flow): JsonResponse
    {
        $this->authorize('update', $flow);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'group_id' => 'nullable|integer',
            'slug' => 'sometimes|string|max:255',
        ]);

        $updated = $this->flowService->updateMeta($flow, $validated);

        return response()->json([
            'status' => true,
            'data' => new ChatbotFlowResource($updated),
        ]);
    }

    public function destroy(ChatbotFlowVersion $flow): JsonResponse
    {
        $this->authorize('delete', $flow);

        $this->flowService->deleteDraft($flow);

        return response()->json(['status' => true]);
    }

    public function duplicate(ChatbotFlowVersion $flow): JsonResponse
    {
        $this->authorize('view', $flow);

        $copy = $this->flowService->duplicate($flow, $this->tenantUserId());

        return response()->json([
            'status' => true,
            'data' => new ChatbotFlowResource($copy),
        ], 201);
    }

    public function publish(PublishChatbotFlowRequest $request, ChatbotFlowVersion $flow): JsonResponse
    {
        $published = $this->publishService->publish(
            $flow,
            $this->tenantUserId(),
            $request->validated('note'),
        );

        return response()->json([
            'status' => true,
            'data' => new ChatbotFlowResource($published),
        ]);
    }

    public function unpublish(ChatbotFlowVersion $flow): JsonResponse
    {
        $this->authorize('publish', $flow);

        $updated = $this->publishService->unpublish($flow);

        return response()->json([
            'status' => true,
            'data' => new ChatbotFlowResource($updated),
        ]);
    }

    public function validateDefinition(ChatbotFlowVersion $flow): JsonResponse
    {
        $this->authorize('view', $flow);

        $result = $this->validator->validate($flow->definition ?? []);

        return response()->json([
            'status' => true,
            ...$result->toArray(),
        ], $result->valid ? 200 : 422);
    }

    public function simulate(SimulateChatbotFlowRequest $request, ChatbotFlowVersion $flow): JsonResponse
    {
        $result = $this->simulationService->step(
            $flow,
            $this->tenantUserId(),
            $request->string('message')->toString(),
            $request->input('wa_id'),
            (bool) $request->boolean('use_draft'),
            (bool) $request->boolean('reset'),
        );

        return response()->json([
            'status' => true,
            'data' => $result->toArray(),
        ]);
    }

    public function sessions(Request $request, ChatbotFlowVersion $flow): JsonResponse
    {
        $this->authorize('view', $flow);

        $perPage = min(100, max(1, (int) $request->query('per_page', 15)));
        $sessions = ConversationSession::query()
            ->forFlowVersion($flow->id)
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        return response()->json([
            'status' => true,
            'data' => ConversationSessionResource::collection($sessions)->response()->getData(true),
        ]);
    }

    public function resetSession(ConversationSession $session): JsonResponse
    {
        if ((int) $session->user_id !== $this->tenantUserId()) {
            abort(403);
        }

        $session->load('flowVersion');
        $this->authorize('view', $session->flowVersion);

        $session->delete();

        return response()->json(['status' => true]);
    }

    public function activeForGroup(int $groupId): JsonResponse
    {
        $this->authorize('viewAny', ChatbotFlowVersion::class);

        $active = $this->flowService->activeForGroup($this->tenantUserId(), $groupId);

        return response()->json([
            'status' => true,
            'data' => $active ? new ChatbotFlowResource($active) : null,
        ]);
    }
}
