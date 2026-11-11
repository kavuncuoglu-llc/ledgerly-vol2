<?php

namespace App\Ai\Profile;

use App\Ai\Hardware\ModelShape;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

// Ollama's /api/ps says how much of a loaded model is on the GPU. Anything left over is on the CPU: the spill of Chapter 22, which is what makes a model run at CPU speed.
final class Residency
{
    /** @return float|null GiB of the model that are not on the GPU; null when the server cannot say (it is not Ollama, or it did not answer) */
    public static function spilledGb(string $baseUrl, string $model): ?float
    {
        $root = preg_replace('#/v1/?$#', '', rtrim($baseUrl, '/'));

        try {
            $loaded = Http::baseUrl($root)->acceptJson()->connectTimeout(2)->timeout(10)->get('/api/ps')->throw()->json('models');
        } catch (ConnectionException|RequestException) {
            return null;
        }

        foreach ($loaded ?? [] as $entry) {
            if (! in_array($model, [$entry['name'] ?? null, $entry['model'] ?? null], true)) {
                continue;
            }

            return max(0.0, (float) ($entry['size'] ?? 0) - (float) ($entry['size_vram'] ?? 0)) / ModelShape::BYTES_PER_GIB;
        }

        return null;
    }
}
