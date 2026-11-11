<div
    x-data="{
        streaming: false,
        text: '',
        status: 'Thinking…',
        failed: '',
        paused: null,
        follow(replyId) {
            this.streaming = true;
            this.text = '';
            this.failed = '';
            this.paused = null;
            this.status = 'Thinking…';

            followReply(replyId, {
                onTool: (tool) => this.status = `Looking up ${tool.replaceAll('_', ' ')}…`,
                onText: (value) => { this.text = value; },
                onCompleted: () => { this.streaming = false; $wire.completed(); },
                onFailed: (content) => { this.streaming = false; this.failed = content; },
                onPaused: (message) => { this.streaming = false; this.paused = message; $wire.completed(); },
            });
        },
    }"
    x-on:reply-started.window="follow($event.detail.replyId)"
>
    <div class="thread">
        @foreach ($messages as $message)
            <div wire:key="message-{{ $message['id'] }}" class="bubble {{ $message['role'] }}">
                <div class="text">{{ $message['content'] }}</div>

                @if ($message['role'] === 'assistant' && $message['question'] !== null && $message['status'] === 'completed')
                    <x-answer-feedback :reply-id="$message['id']" :rating="$message['feedback']" />
                @endif
            </div>
        @endforeach

        <div class="bubble assistant streaming" x-show="streaming" x-cloak>
            <p class="muted" x-show="text === ''" x-text="status"></p>
            <div class="text" x-text="text"></div>
        </div>

        <div class="bubble assistant failed" x-show="failed !== ''" x-cloak>
            <div class="text" x-text="failed"></div>
        </div>

        <div class="approval-card" x-show="paused !== null" x-cloak>
            <p x-text="paused?.pending?.summary"></p>
            <a :href="paused?.pending ? `/support/approvals/${paused.pending.id}` : '#'">Review and approve</a>
        </div>
    </div>

    <form wire:submit="send" class="ask-form">
        <label for="chat-question">Your question</label>
        <textarea id="chat-question" wire:model="question" rows="3" maxlength="2000" required></textarea>
        @error('question') <p class="error">{{ $message }}</p> @enderror
        <button type="submit">Ask</button>
    </form>
</div>
