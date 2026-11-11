<?php

namespace App\Ai\Widget;

use Illuminate\Support\Facades\Cache;

// Chapter 35, Topic 7: the public widget's bot challenge. No third-party script: the visitor's browser spends a fraction of a second finding a nonce whose hash starts with a few zeros, once per question,
// which is nothing to a person and a real cost to a script sending a million long prompts. A challenge is signed, expires, and works once. The session id is signed too, so a per-session budget cannot be dodged by inventing one.
final class ProofOfWork
{
    public function issue(?string $session = null): array
    {
        $id = $this->validSession($session) !== null
            ? explode('.', (string) $session, 2)[0]
            : bin2hex(random_bytes(16));

        $session = $id.'.'.$this->signature($id);

        $payload = base64_encode(json_encode(['session' => $session, 'issued_at' => now()->timestamp, 'salt' => bin2hex(random_bytes(8))]));

        return [
            'session' => $session,
            'token' => $payload.'.'.$this->signature($payload),
            'difficulty' => $this->difficulty(),
        ];
    }

    public function verify(string $token, string $nonce, string $session): bool
    {
        [$payload, $signature] = array_pad(explode('.', $token, 2), 2, '');

        if (! hash_equals($this->signature($payload), $signature)) {
            return false;
        }

        $data = json_decode((string) base64_decode($payload, true), true);

        if (! is_array($data) || ($data['session'] ?? null) !== $session) {
            return false;
        }

        if (now()->timestamp - (int) ($data['issued_at'] ?? 0) > (int) config('ai.widget.challenge.ttl_seconds', 300)) {
            return false;
        }

        if (! str_starts_with(hash('sha256', $token.':'.$nonce), str_repeat('0', $this->difficulty()))) {
            return false;
        }

        return Cache::add('widget:challenge:'.hash('sha256', $token), 1, 600);                // a solved challenge is spent
    }

    public function validSession(?string $session): ?string
    {
        if ($session === null || ! str_contains($session, '.')) {
            return null;
        }

        [$id, $signature] = explode('.', $session, 2);

        return preg_match('/^[a-f0-9]{32}$/', $id) === 1 && hash_equals($this->signature($id), $signature)
            ? $session
            : null;
    }

    public function difficulty(): int
    {
        return max(0, (int) config('ai.widget.challenge.difficulty', 4));
    }

    private function signature(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
