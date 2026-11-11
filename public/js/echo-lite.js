// A small Echo-shaped client for Laravel Reverb (the Pusher protocol), so the chat page needs no Node and no build step.
// It implements the part of Laravel Echo this book uses: Echo.private(name).listen(event, callback), Echo.leave(name),
// plus connection state and a reconnect hook. If you prefer the real thing, `npm install laravel-echo pusher-js` and swap this file out:
// the pages only call the methods below.
(function () {
    const meta = document.querySelector('meta[name="reverb-config"]');
    const config = meta ? JSON.parse(meta.content) : null;

    if (!config) {
        return;
    }

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    const channels = new Map();
    const stateListeners = [];
    const reconnectListeners = [];

    let socket = null;
    let socketId = null;
    let state = 'connecting';
    let attempt = 0;
    let everConnected = false;
    let closing = false;

    const matches = (received, wanted) => received === wanted || received.endsWith(`\\${wanted}`) || received.endsWith(`.${wanted}`);

    const setState = (next) => {
        if (state === next) {
            return;
        }

        state = next;

        stateListeners.forEach((callback) => callback(state));
    };

    const send = (event, data) => {
        if (socket && socket.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify({ event, data }));
        }
    };

    const authorise = async (name) => {
        const response = await fetch(config.authEndpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({ socket_id: socketId, channel_name: name }),
        });

        if (!response.ok) {
            throw new Error(`Subscription to ${name} refused (HTTP ${response.status}).`);
        }

        return response.json();
    };

    const subscribe = async (channel) => {
        if (!socketId) {
            return;
        }

        try {
            const { auth } = await authorise(channel.name);

            send('pusher:subscribe', { auth, channel: channel.name });
        } catch (error) {
            channel.errors.forEach((callback) => callback(error));
        }
    };

    const open = () => {
        const scheme = config.scheme === 'https' ? 'wss' : 'ws';

        socket = new WebSocket(`${scheme}://${config.host}:${config.port}/app/${config.key}?protocol=7&client=js&version=ledgerly-1&flash=false`);

        socket.onmessage = (message) => {
            const frame = JSON.parse(message.data);

            if (frame.event === 'pusher:connection_established') {
                socketId = JSON.parse(frame.data).socket_id;

                attempt = 0;

                setState('connected');

                if (everConnected) {
                    reconnectListeners.forEach((callback) => callback());
                }

                everConnected = true;

                channels.forEach(subscribe);

                return;
            }

            if (frame.event === 'pusher:ping') {
                send('pusher:pong', {});

                return;
            }

            const channel = channels.get(frame.channel);

            if (!channel) {
                return;
            }

            const payload = typeof frame.data === 'string' && frame.data !== '' ? JSON.parse(frame.data) : frame.data;

            channel.listeners.forEach(({ event, callback }) => {
                if (matches(frame.event, event)) {
                    callback(payload);
                }
            });
        };

        socket.onclose = () => {
            socketId = null;

            if (closing) {
                return;
            }

            setState('disconnected');

            const delay = Math.min(15000, 1000 * 2 ** attempt);

            attempt += 1;

            setTimeout(open, delay);
        };

        socket.onerror = () => {};                                          // a failed connection is always followed by a close event, which reconnects
    };

    const channelFor = (name) => {
        if (!channels.has(name)) {
            const channel = {
                name,
                listeners: [],
                errors: [],
                listen(event, callback) {
                    channel.listeners.push({ event, callback });

                    return channel;
                },
                error(callback) {
                    channel.errors.push(callback);

                    return channel;
                },
            };

            channels.set(name, channel);

            subscribe(channel);
        }

        return channels.get(name);
    };

    window.Echo = {
        private: (name) => channelFor(`private-${name}`),
        leave: (name) => {
            const full = `private-${name}`;

            if (channels.has(full)) {
                send('pusher:unsubscribe', { channel: full });

                channels.delete(full);
            }
        },
        state: () => state,
        onState: (callback) => stateListeners.push(callback),
        onReconnect: (callback) => reconnectListeners.push(callback),
        disconnect: () => {
            closing = true;

            socket?.close();
        },
    };

    open();
})();
