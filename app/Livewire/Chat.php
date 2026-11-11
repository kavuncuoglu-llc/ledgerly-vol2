<?php

namespace App\Livewire;

use App\Ai\Streaming\ReplyStarter;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

// The Livewire flavour of the chat (Fig 18-5): Alpine renders the stream straight from the channel, Livewire owns the stored record.
class Chat extends Component
{
    #[Locked]
    public ?int $conversationId = null;                                            // locked: a browser cannot point this component at somebody else's conversation

    public string $question = '';

    public array $messages = [];

    public function mount(): void
    {
        abort_if(auth()->user()->tenant_id === null, 403, 'The assistant is for customer accounts.');

        $this->messages = [];
    }

    public function send(ReplyStarter $starter): void
    {
        abort_if(auth()->user()->tenant_id === null, 403, 'The assistant is for customer accounts.');

        $this->validate(['question' => ['required', 'string', 'max:2000']]);

        $reply = $starter->start(auth()->user(), $this->question, $this->openConversation());

        $this->conversationId = $reply->conversation_id;

        $this->question = '';

        $this->completed();

        $this->dispatch('reply-started', replyId: $reply->id);
    }

    #[On('reply-finished')]
    public function completed(): void
    {
        if ($this->conversationId === null) {
            return;
        }

        $this->messages = Message::query()                                         // the final record wins over whatever was streamed
            ->where('conversation_id', $this->openConversation()?->id)
            ->whereIn('role', ['user', 'assistant'])
            ->where(fn ($query) => $query->where('status', 'completed')->orWhere('status', 'paused'))
            ->whereNotNull('content')
            ->orderBy('seq')
            ->get(['id', 'role', 'content', 'status'])
            ->toArray();
    }

    public function render(): View
    {
        return view('livewire.chat');
    }

    private function openConversation(): ?Conversation
    {
        if ($this->conversationId === null) {
            return null;
        }

        return Conversation::query()
            ->where('user_id', auth()->id())
            ->where('status', 'open')
            ->findOrFail($this->conversationId);
    }
}
