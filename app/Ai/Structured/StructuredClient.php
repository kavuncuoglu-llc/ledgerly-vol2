<?php

namespace App\Ai\Structured;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Schema\SchemaBuilder;
use Illuminate\Support\Facades\Validator;
use JsonException;

final class StructuredClient
{
    public function __construct(private AiManager $ai, private SchemaBuilder $schemas) {}

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function extract(string $class, array $messages, array $options = [], string $feature = 'structured'): object
    {
        $options += ['profile' => 'deterministic'] + $this->responseFormat($class);

        $attempts = [];

        for ($i = 0; $i < 2; $i++) {
            $response = $this->ai->for($feature)->chat(ChatRequest::make($messages, $options + ['repaired' => $i > 0], $feature));

            $data = $this->decode((string) $response->content);   // level 3: cannot fail; if it does, enforcement is off

            $attempts[] = $data;

            if (($data['error'] ?? null) !== null) {
                throw new StructuredRefusal($class, (string) $data['error'], $attempts);   // sanctioned refusal: no repair
            }

            $v = Validator::make($data, $class::rules());

            foreach ($class::afterRules() as $after) {
                $v->after($after);
            }

            if ($v->passes()) {
                return $class::fromArray($v->validated(), new ExtractionMeta(repaired: $i > 0, attempts: $i + 1));
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => RepairPrompt::for($v->errors())];
        }

        throw new StructuredOutputFailed($class, $attempts, $v->errors());
    }

    private function decode(string $content): array
    {
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new JsonException('The model returned JSON that is not an object.');
        }

        return $data;
    }

    private function responseFormat(string $class): array
    {
        return match (config('ai.structured_level', 'schema')) {
            'ask' => [],
            'json' => ['response_format' => ['type' => 'json_object']],
            default => ['response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $this->schemas->name($class),
                    'strict' => true,
                    'schema' => $this->schemas->for($class),
                ],
            ]],
        };
    }
}
