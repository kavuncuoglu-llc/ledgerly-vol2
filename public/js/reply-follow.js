// Follows one reply (Chapter 18, Topics 4 and 5). Deltas arrive over the private channel; the stored message is the truth.
// If the socket is missing, drops, or never delivers the completion, a poll of GET /replies/{id} gets the page to the same place.
window.followReply = function (replyId, handlers) {
    const codePoints = (text) => Array.from(text);

    let text = '';
    let finished = false;
    let timer = null;
    let sawFirstDelta = false;

    const finish = () => {
        finished = true;

        clearTimeout(timer);

        window.Echo?.leave(`reply.${replyId}`);
    };

    const fetchSnapshot = async () => {
        const response = await fetch(`/replies/${replyId}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        return response.json();
    };

    const apply = (message) => {
        if (finished) {
            return;
        }

        if (message.status === 'completed') {
            finish();

            handlers.onCompleted(message);

            return;
        }

        if (message.status === 'failed') {
            finish();

            handlers.onFailed(message.content);

            return;
        }

        if (message.status === 'truncated') {
            finish();

            handlers.onFailed(message.content);

            return;
        }

        if (message.status === 'blocked') {
            finish();

            handlers.onFailed(message.content);

            return;
        }

        if (message.status === 'paused') {
            finish();

            handlers.onPaused(message);

            return;
        }

        if (codePoints(message.content).length > codePoints(text).length) {
            text = message.content;

            handlers.onText(text);
        }
    };

    const refresh = async () => {
        try {
            apply(await fetchSnapshot());
        } catch (error) {
            handlers.onTrouble?.(error);
        }
    };

    const poll = async () => {
        if (finished) {
            return;
        }

        await refresh();

        const connected = window.Echo?.state() === 'connected';

        timer = setTimeout(poll, connected ? 4000 : 1200);                  // a safety net when connected, the transport when not
    };

    if (window.Echo) {
        window.Echo.private(`reply.${replyId}`)
            .listen('ToolCallStarted', (event) => handlers.onTool?.(event.tool))
            .listen('ReplyDelta', (event) => {
                const have = codePoints(text).length;

                if (event.o > have) {
                    refresh();                                            // we missed some: the stored message knows

                    return;
                }

                const fresh = codePoints(event.t).slice(have - event.o).join('');

                if (fresh === '') {
                    return;
                }

                if (!sawFirstDelta) {
                    sawFirstDelta = true;

                    handlers.onFirstDelta?.();
                }

                text += fresh;

                handlers.onText(text);
            })
            .listen('ReplyPaused', () => refresh())
            .listen('ReplyCompleted', () => refresh())
            .listen('ReplyFailed', () => refresh())
            .error((error) => handlers.onTrouble?.(error));

        window.Echo.onReconnect(() => refresh());
    }

    timer = setTimeout(poll, 700);                                         // also covers a reply that finished before the subscription was ready

    return { stop: finish };
};
