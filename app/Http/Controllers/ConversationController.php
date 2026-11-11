<?php

namespace App\Http\Controllers;

use App\Ai\Conversations\ConversationStore;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function close(Request $request, Conversation $conversation, ConversationStore $store): JsonResponse
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);

        $store->close($conversation);                                 // dispatches ExtractMemories

        return response()->json(['conversation_id' => $conversation->id, 'status' => $conversation->fresh()->status]);
    }
}
