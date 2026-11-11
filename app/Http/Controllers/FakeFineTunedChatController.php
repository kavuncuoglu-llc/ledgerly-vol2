<?php

namespace App\Http\Controllers;

use App\Evals\GoldenCase;
use App\Evals\GoldenSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Chapter 41, Topic 8: "the fake chat driver answers with scripted billing replies". An OpenAI-shaped chat endpoint with no model behind it: it finds the golden case whose question is in the request and answers from the case's reference answer. A model ID that starts with `ft:fake-billing` (what the fake fine-tuning driver returns) gets the whole reference answer; any other model gets it without its last sentence, so the required facts differ between a fine-tune and its base the way a real fine-tune's would, and nothing else about the numbers means anything.
final class FakeFineTunedChatController extends Controller
{
    public function __invoke(Request $request, GoldenSet $set): JsonResponse|Response
    {
        $model = (string) $request->input('model', 'fake-base');

        $text = $this->haystack((array) $request->input('messages', []));

        $case = $set->all()->first(fn (GoldenCase $case) => $case->question !== '' && str_contains($text, $case->question));

        $reply = $this->reply($case, str_starts_with($model, 'ft:fake-billing'));

        $usage = ['prompt_tokens' => (int) ceil(strlen($text) / 4), 'completion_tokens' => (int) ceil(strlen($reply) / 4), 'total_tokens' => (int) ceil((strlen($text) + strlen($reply)) / 4)];

        if ($request->boolean('stream')) {
            return $this->stream($model, $reply, $usage);
        }

        return response()->json([
            'id' => 'chatcmpl-fake',
            'object' => 'chat.completion',
            'model' => $model,
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $reply], 'finish_reason' => 'stop']],
            'usage' => $usage,
        ]);
    }

    private function reply(?GoldenCase $case, bool $fineTuned): string
    {
        if ($case === null) {
            return 'I can only help with Ledgerly questions.';
        }

        $answer = $case->referenceAnswer !== ''
            ? $case->referenceAnswer
            : implode(' ', $case->mustInclude);

        if ($fineTuned) {
            return $answer;
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $answer) ?: [$answer];

        return count($sentences) > 1
            ? implode(' ', array_slice($sentences, 0, -1))
            : $answer;
    }

    private function haystack(array $messages): string
    {
        return implode("\n", array_map(fn (array $message) => is_string($message['content'] ?? null) ? $message['content'] : json_encode($message['content'] ?? ''), $messages));
    }

    private function stream(string $model, string $reply, array $usage): StreamedResponse
    {
        return response()->stream(function () use ($model, $reply, $usage): void {
            $chunk = fn (array $delta, ?string $finish, array $extra = []) => 'data: '.json_encode(['id' => 'chatcmpl-fake', 'object' => 'chat.completion.chunk', 'model' => $model, 'choices' => [['index' => 0, 'delta' => $delta, 'finish_reason' => $finish]]] + $extra)."\n\n";

            echo $chunk(['role' => 'assistant', 'content' => ''], null);

            echo $chunk(['content' => $reply], null);

            echo $chunk([], 'stop', ['usage' => $usage]);

            echo "data: [DONE]\n\n";
        }, 200, ['Content-Type' => 'text/event-stream']);
    }
}
