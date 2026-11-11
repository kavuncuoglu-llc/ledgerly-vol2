const form = document.getElementById('widget-form');
const input = document.getElementById('widget-question');
const answer = document.getElementById('widget-answer');
const sources = document.getElementById('widget-sources');

let session = '';

const sha256Hex = async (text) => {
    const bytes = new TextEncoder().encode(text);

    const digest = await crypto.subtle.digest('SHA-256', bytes);

    return Array.from(new Uint8Array(digest)).map((byte) => byte.toString(16).padStart(2, '0')).join('');
};

const solve = async (token, difficulty) => {
    const prefix = '0'.repeat(difficulty);

    for (let nonce = 0; ; nonce++) {
        const hash = await sha256Hex(`${token}:${nonce}`);

        if (hash.startsWith(prefix)) {
            return String(nonce);
        }
    }
};

const render = (result) => {
    answer.textContent = result.answer;

    sources.replaceChildren(...result.sources.map((source) => {
        const item = document.createElement('li');

        const link = document.createElement('a');

        link.href = source.url;

        link.textContent = source.label;

        item.append(link);

        return item;
    }));
};

form.addEventListener('submit', async (event) => {
    event.preventDefault();

    answer.textContent = 'Thinking…';

    const challenge = await (await fetch(`${form.dataset.challengeUrl}?session=${encodeURIComponent(session)}`)).json();

    session = challenge.session;

    const nonce = await solve(challenge.token, challenge.difficulty);

    const response = await fetch(form.dataset.askUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ question: input.value, session, token: challenge.token, nonce }),
    });

    render(await response.json());
});
