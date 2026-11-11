<?php

namespace App\Ai\Tokens;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Chapter 23, Topic 9: the exact counter Chapter 2 wanted. llama-server tokenizes with the loaded model's own vocabulary, so for the model it serves the budget math is not an estimate.
// If the server cannot be asked, the ratio estimate stands in (and says so once), so a stopped server never turns a budget check into an exception.
final class LlamaServerTokenCounter implements TokenCounter
{
    private array $memo = [];

    private bool $warned = false;

    private bool $unavailable = false;                // once the server has failed to answer, stop paying a connection timeout per count

    public function __construct(
        private string $baseUrl,
        private ?string $apiKey,
        private TokenCounter $fallback = new RatioCounter,
        private int $timeout = 5,
    ) {}

    public function count(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        if ($this->unavailable) {
            return $this->fallback->count($text);
        }

        $key = md5($text);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        try {
            $tokens = Http::baseUrl(preg_replace('#/v1/?$#', '', rtrim($this->baseUrl, '/')))
                ->withToken((string) $this->apiKey)
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout($this->timeout)
                ->post('/tokenize', ['content' => $text])
                ->throw()
                ->json('tokens');
        } catch (ConnectionException|RequestException $e) {
            return $this->estimated($text, $e->getMessage());
        }

        if (! is_array($tokens)) {
            return $this->estimated($text, 'the answer had no tokens list');
        }

        if (count($this->memo) > 500) {
            $this->memo = [];
        }

        return $this->memo[$key] = count($tokens);
    }

    private function estimated(string $text, string $reason): int
    {
        $this->unavailable = true;

        if (! $this->warned) {
            $this->warned = true;

            Log::warning('llama-server could not tokenize; using the ratio estimate.', ['reason' => strtok($reason, "\n")]);
        }

        return $this->fallback->count($text);
    }
}
