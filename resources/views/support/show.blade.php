@extends('layouts.app')

@section('title', 'Ask support')

@push('head')
    @include('support.partials.reverb-config')
@endpush

@section('content')
    <h1>Ask the support assistant</h1>

    <x-assistant-disclosure />

    <p class="muted">Your question is queued, a worker runs the model, and the answer streams in over a private channel (Chapter 18). If the socket is down, the page falls back to polling the stored reply. The Livewire version of this page is <a href="{{ route('support.chat') }}">here</a>.</p>

    <div id="thread" class="thread" aria-live="polite"></div>

    <form id="ask-form" class="ask-form" data-url="{{ route('support.ask') }}">
        <label for="question">Your question</label>
        <textarea id="question" name="question" rows="3" maxlength="2000" required></textarea>
        <button type="submit" id="ask-button">Ask</button>
    </form>

    <div class="demo-questions">
        <span class="muted">Try:</span>
        @foreach ($demoQuestions as $demoQuestion)
            <button type="button" class="link-button" data-demo="{{ $demoQuestion }}">{{ $demoQuestion }}</button>
        @endforeach
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/echo-lite.js') }}"></script>
    <script src="{{ asset('js/reply-follow.js') }}"></script>
    <script>
        const form = document.getElementById('ask-form');
        const questionField = document.getElementById('question');
        const askButton = document.getElementById('ask-button');
        const thread = document.getElementById('thread');
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        let conversationId = null;

        const post = (url, body) => fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body ?? {}),
        });

        const element = (tag, className, text) => {
            const node = document.createElement(tag);

            if (className) {
                node.className = className;
            }

            if (text !== undefined) {
                node.textContent = text;
            }

            return node;
        };

        const bubble = (role) => {
            const node = element('div', `bubble ${role}`);

            thread.append(node);

            return node;
        };

        const showSources = (node, sources) => {
            if (!sources?.length) {
                return;
            }

            const line = element('p', 'muted', 'Sources: ');

            sources.forEach((source, index) => {
                const link = element('a', null, `[${source.label}]`);

                link.href = source.url;

                line.append(index === 0 ? '' : ' ', link);
            });

            node.append(line);
        };

        const reasons = [['wrong_answer', 'Wrong answer'], ['did_not_answer', 'Did not answer my question'], ['wanted_person', 'I wanted a person']];

        const showFeedback = (node, replyId) => {
            const row = element('p', 'feedback');

            const rate = async (rating, reason = null) => {
                await post(`/replies/${replyId}/feedback`, { rating, reason });

                row.textContent = 'Thanks for the feedback.';
            };

            const button = (label, onClick) => {
                const control = element('button', 'link-button', label);

                control.type = 'button';

                control.addEventListener('click', onClick);

                return control;
            };

            const askWhy = () => {
                row.replaceChildren('What went wrong? ');

                reasons.forEach(([reason, label]) => row.append(button(label, () => rate('down', reason)), ' '));
            };

            row.append(button('Helpful', () => rate('up')), ' ', button('Not helpful', askWhy));

            node.append(row);
        };

        const showApproval = (node, message) => {
            const card = element('div', 'approval-card');

            card.append(element('p', null, message.pending.summary));

            [['Approve', message.pending.approve_url], ['Decline', message.pending.decline_url]].forEach(([label, url]) => {
                const button = element('button', null, label);

                button.type = 'button';

                button.addEventListener('click', async () => {
                    card.querySelectorAll('button').forEach((each) => each.disabled = true);

                    const response = await post(url);
                    const payload = await response.json();

                    if (!response.ok) {
                        card.append(element('p', 'error', payload.message ?? `The request failed (HTTP ${response.status}).`));

                        return;
                    }

                    card.remove();

                    follow(payload.reply_id, bubble('assistant'));
                });

                card.append(button, ' ');
            });

            node.append(card);
        };

        const follow = (replyId, node) => {
            node.classList.add('pending');

            const text = element('div', 'text');
            const status = element('p', 'muted', 'Thinking…');

            node.replaceChildren(status, text);

            followReply(replyId, {
                onTool: (tool) => status.textContent = `Looking up ${tool.replaceAll('_', ' ')}…`,
                onFirstDelta: () => {
                    node.classList.replace('pending', 'streaming');

                    status.hidden = true;
                },
                onText: (value) => {
                    status.hidden = true;

                    text.textContent = value;                                  // plain text while it streams: partial Markdown flickers
                },
                onCompleted: (message) => {
                    node.classList.remove('pending', 'streaming');

                    status.hidden = true;

                    text.textContent = message.content;

                    showSources(node, message.sources);
                    showFeedback(node, replyId);
                },
                onPaused: (message) => {
                    node.classList.remove('pending', 'streaming');

                    status.hidden = true;

                    text.textContent = message.content;

                    if (message.pending) {
                        showApproval(node, message);
                    }
                },
                onFailed: (content) => {
                    node.classList.remove('pending', 'streaming');
                    node.classList.add('failed');

                    status.hidden = true;

                    text.textContent = content;

                    const retry = element('button', 'link-button', 'Try again');

                    retry.type = 'button';
                    retry.addEventListener('click', () => ask(node.dataset.question));

                    node.append(retry);
                },
            });
        };

        const ask = async (question) => {
            askButton.disabled = true;

            bubble('user').textContent = question;

            const reply = bubble('assistant');

            reply.dataset.question = question;

            reply.textContent = 'Sending…';

            const response = await post(form.dataset.url, { question, conversation_id: conversationId });
            const payload = await response.json();

            askButton.disabled = false;

            if (!response.ok) {
                reply.classList.add('failed');
                reply.textContent = payload.message ?? `The request failed (HTTP ${response.status}).`;

                return;
            }

            conversationId = payload.conversation_id;

            follow(payload.reply_id, reply);
        };

        document.querySelectorAll('[data-demo]').forEach((button) => {
            button.addEventListener('click', () => {
                questionField.value = button.dataset.demo;
                form.requestSubmit();
            });
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            const question = questionField.value.trim();

            questionField.value = '';

            if (question !== '') {
                ask(question);
            }
        });
    </script>
@endpush
