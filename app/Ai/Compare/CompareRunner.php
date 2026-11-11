<?php

namespace App\Ai\Compare;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\ChatResponse;
use App\Ai\Contracts\Stream\Delta;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Prompts\TicketClassifyPrompt;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use Generator;
use InvalidArgumentException;
use Throwable;

// Chapter 20, Topic 8: the same golden requests against every named provider, scored the same way, costed the same way. The request is built once per case and sent unchanged.
final class CompareRunner
{
    public function __construct(
        private AiManager $ai,
        private Retriever $retriever,
        private Scorers $scorers,
        private CostModel $cost,
    ) {}

    /**
     * @param  list<GoldenCase>  $cases
     * @param  list<string>  $providers  provider names (already resolved from tiers)
     */
    public function run(array $cases, array $providers, bool $stream = false): CompareResult
    {
        $samples = [];

        foreach ($cases as $case) {
            try {
                [$request, $labelMap] = $this->prepare($case);
            } catch (Throwable $e) {
                foreach ($providers as $provider) {
                    $samples[] = ['provider' => $provider, 'case' => $case, 'error' => 'could not build the request: '.$e->getMessage()];
                }

                continue;
            }

            foreach ($providers as $provider) {
                $samples[] = $this->ask($provider, $case, $request, $labelMap, $stream);
            }
        }

        return new CompareResult($samples, $providers);
    }

    /** @return array{0: ChatRequest, 1: array<string, object>} */
    private function prepare(GoldenCase $case): array
    {
        return match ($case->type) {
            GoldenCase::EXACT => [
                $case->withRequest(TicketClassifyPrompt::messages((string) $case->input['message']), ['profile' => 'deterministic', 'max_tokens' => 4, 'prompt_version' => TicketClassifyPrompt::VERSION]),
                [],
            ],
            GoldenCase::RECALL => $this->grounded($case),
            GoldenCase::REPLAY => [$case->withRequest($case->input['messages'], ($case->input['options'] ?? []) + array_filter(['profile' => $case->input['profile'] ?? null, 'tools' => $case->input['tools'] ?? null, 'response_format' => $case->input['response_format'] ?? null])), []],
            default => throw new InvalidArgumentException("Unknown golden case type '{$case->type}'."),
        };
    }

    /** @return array{0: ChatRequest, 1: array<string, object>} */
    private function grounded(GoldenCase $case): array
    {
        $question = (string) $case->input['question'];

        $chunks = $this->retriever->retrieve($question, RetrievalScope::shared(), (int) config('ai.retrieval.k', 5));   // shared help text only: nothing of any customer goes to a provider being compared

        $prompt = SupportPrompt::assemble(question: $question, contextChunks: $chunks);

        return [
            $case->withRequest($prompt->messages, ['profile' => 'assistant', 'max_tokens' => config('ai.budget.answer'), 'prompt_version' => SupportPrompt::VERSION]),
            $prompt->labelMap,
        ];
    }

    private function ask(string $provider, GoldenCase $case, ChatRequest $request, array $labelMap, bool $stream): array
    {
        $model = $this->ai->chat($provider);

        $started = hrtime(true);

        $ttft = null;

        try {
            $response = $stream
                ? $this->streamed($model->stream($request), $started, $ttft)
                : $model->chat($request);
        } catch (Throwable $e) {
            return ['provider' => $provider, 'case' => $case, 'error' => class_basename($e).': '.$e->getMessage()];
        }

        $ms = (int) ((hrtime(true) - $started) / 1_000_000);

        $reply = (string) $response->content;

        $scores = array_filter([
            match ($case->type) {
                GoldenCase::EXACT => $this->scorers->exact($case, $reply),
                GoldenCase::RECALL => $this->scorers->recall($case, $reply, $labelMap),
                default => null,
            },
            $case->type === GoldenCase::EXACT ? null : $this->scorers->judged($case, $reply),
        ], fn ($score) => $score !== null);

        return [
            'provider' => $provider,
            'case' => $case,
            'quality' => $scores === []
                ? null
                : array_sum($scores) / count($scores),
            'in' => $response->usage->promptTokens,
            'out' => $response->usage->completionTokens,
            'ms' => $ms,
            'ttft' => $ttft,
            'cost' => $this->cost->of($provider, $response->model, $response->usage, $ms),
            'error' => null,
        ];
    }

    private function streamed(Generator $events, int|float $started, ?int &$ttft): ChatResponse
    {
        foreach ($events as $event) {
            if ($event instanceof Delta) {
                $ttft ??= (int) ((hrtime(true) - $started) / 1_000_000);
            }
        }

        return $events->getReturn();
    }
}
