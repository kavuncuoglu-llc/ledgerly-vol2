<?php

use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Ai\Widget\ProofOfWork;

// Chapter 35, Topic 7: what the browser does, in PHP. Finds the nonce for a challenge, so a test can post a question through the whole door.
function solvedChallenge(?string $session = null): array
{
    $challenge = app(ProofOfWork::class)->issue($session);

    $nonce = 0;

    while (! str_starts_with(hash('sha256', $challenge['token'].':'.$nonce), str_repeat('0', $challenge['difficulty']))) {
        $nonce++;
    }

    return ['session' => $challenge['session'], 'token' => $challenge['token'], 'nonce' => (string) $nonce];
}

function widgetPost(string $question, ?array $solved = null)
{
    return test()->postJson('/widget/ask', ['question' => $question] + ($solved ?? solvedChallenge()));
}

function fakeSharedRetrieval(array $chunks): void
{
    app()->instance(Retriever::class, new class($chunks) implements Retriever
    {
        public ?RetrievalScope $scope = null;

        public function __construct(private array $chunks) {}

        public function retrieve(string $question, RetrievalScope $scope, int $k = 5): array
        {
            $this->scope = $scope;

            return $this->chunks;
        }
    });
}
