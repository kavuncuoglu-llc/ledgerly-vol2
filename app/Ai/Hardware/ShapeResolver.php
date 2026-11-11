<?php

namespace App\Ai\Hardware;

use App\Ai\Local\OllamaNative;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

// Ask the server what the model is (Ollama's /api/show and /api/tags); when it cannot say, read the tag and the Llama table in ModelCatalog; say which one answered.
final class ShapeResolver
{
    public function __construct(private ?OllamaNative $ollama = null) {}

    public function resolve(string $model, array $overrides = []): ModelShape
    {
        $fromServer = $this->fromOllama($model);

        $parameters = $fromServer['parameters'] ?? ModelCatalog::parametersFromName($model);
        $quantization = $fromServer['quantization'] ?? ModelCatalog::quantizationFromName($model);
        $source = $fromServer === []
            ? 'estimate'
            : 'ollama';

        $shape = $fromServer['shape'] ?? ModelCatalog::llamaShape($parameters);

        if ($fromServer === [] && $shape !== null) {
            $source = 'catalog';
        }

        return new ModelShape(
            name: $model,
            parametersBillions: $parameters,
            quantization: $quantization,
            layers: $overrides['layers'] ?? $shape[0] ?? null,
            kvHeads: $overrides['kv_heads'] ?? $shape[1] ?? null,
            headDimension: $overrides['head_dim'] ?? $shape[2] ?? null,
            weightsBytes: $overrides['weights_bytes'] ?? $fromServer['weights_bytes'] ?? null,
            source: $source,
        );
    }

    /** @return array{parameters?: float, quantization?: string, shape?: array{0: int, 1: int, 2: int}|null, weights_bytes?: float} empty when the server did not answer */
    private function fromOllama(string $model): array
    {
        $ollama = $this->ollama ?? OllamaNative::fromConfig();

        try {
            $show = $ollama->show($model);
        } catch (ConnectionException|RequestException) {
            return [];
        }

        $info = $show['model_info'] ?? [];

        $found = [
            'parameters' => $this->parameters($show, $info),
            'quantization' => $show['details']['quantization_level'] ?? null,
            'shape' => $this->attentionShape($info),
            'weights_bytes' => $this->weightsBytes($ollama, $model),
        ];

        return array_filter($found, fn ($value) => $value !== null && $value !== 0.0);
    }

    private function parameters(array $show, array $info): ?float
    {
        if (isset($info['general.parameter_count'])) {
            return round(((float) $info['general.parameter_count']) / 1_000_000_000, 1);
        }

        $size = (string) ($show['details']['parameter_size'] ?? '');

        if (preg_match('/^(\d+(?:\.\d+)?)\s*B$/i', $size, $match) === 1) {
            return (float) $match[1];
        }

        return null;
    }

    private function attentionShape(array $info): ?array
    {
        $architecture = (string) ($info['general.architecture'] ?? '');

        if ($architecture === '') {
            return null;
        }

        $layers = $info["{$architecture}.block_count"] ?? null;
        $heads = $info["{$architecture}.attention.head_count"] ?? null;
        $kvHeads = $info["{$architecture}.attention.head_count_kv"] ?? $heads;
        $embedding = $info["{$architecture}.embedding_length"] ?? null;
        $keyLength = $info["{$architecture}.attention.key_length"] ?? null;

        if ($layers === null || $kvHeads === null) {
            return null;
        }

        $headDimension = $keyLength ?? (($heads && $embedding)
            ? intdiv((int) $embedding, (int) $heads)
            : null);

        if ($headDimension === null) {
            return null;
        }

        return [(int) $layers, (int) $kvHeads, (int) $headDimension];
    }

    private function weightsBytes(OllamaNative $ollama, string $model): ?float
    {
        try {
            $tags = Http::baseUrl($ollama->rootUrl())->acceptJson()->connectTimeout(2)->timeout(10)->get('/api/tags')->throw()->json('models') ?? [];
        } catch (ConnectionException|RequestException) {
            return null;
        }

        foreach ($tags as $entry) {
            $name = (string) ($entry['name'] ?? $entry['model'] ?? '');

            if ($name === $model || $name === $model.':latest') {
                return isset($entry['size'])
                    ? (float) $entry['size']
                    : null;
            }
        }

        return null;
    }
}
