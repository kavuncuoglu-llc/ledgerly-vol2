<?php

namespace App\Ai\Distillation;

use App\Ai\AiManager;
use Illuminate\Support\Facades\Http;
use Throwable;

// Chapter 46, Topic 2, Figure 46-2: "a teacher without a recorded basis can't be used for distillation". Whether a provider's terms or an open license permit training on its outputs is a question for whoever reviews licenses and contracts;
// what engineering owns is that the answer is recorded beside the teacher in config (distillation_basis: license, when it was reviewed, by whom) and that nothing is generated without it.
class TeacherCheck
{
    public function __construct(private AiManager $ai) {}

    public function teacher(string $role): Teacher
    {
        $config = config('ai.providers')[$role] ?? null;

        if (! is_array($config)) {
            throw new TeacherRefused("There is no provider [{$role}] to use as a teacher.");
        }

        if (! $this->ai->configured($role)) {
            throw new TeacherRefused("The teacher [{$role}] is not set up (no URL or model): a role that is not there cannot answer a question.");
        }

        $basis = $config['distillation_basis'] ?? null;

        foreach (['license', 'reviewed', 'by'] as $field) {
            if (! is_array($basis) || blank($basis[$field] ?? null)) {
                throw new TeacherRefused("The teacher [{$role}] has no recorded basis for training on its outputs (ai.providers.{$role}.distillation_basis needs license, reviewed and by). Settle the question first, then record the answer.");
            }
        }

        return new Teacher(
            role: $role,
            model: (string) $config['model'],
            version: $this->version($config),
            region: $config['privacy']['region'] ?? $config['region'] ?? null,
            basis: ['license' => (string) $basis['license'], 'reviewed' => (string) $basis['reviewed'], 'by' => (string) $basis['by']],
        );
    }

    // The exact version: the digest recorded in config, else the one the server reports for the model now, else the model name alone.
    private function version(array $config): string
    {
        $digest = (string) ($config['digest'] ?? '');

        if ($digest !== '') {
            return $config['model'].'@'.$digest;
        }

        if (($config['native'] ?? null) === 'ollama') {
            try {
                $tags = Http::timeout(3)->get(preg_replace('#/v1/?$#', '', (string) $config['base_url']).'/api/tags')->json('models', []);

                $reported = collect($tags)->first(fn (array $model) => in_array($model['name'] ?? '', [$config['model'], $config['model'].':latest'], true))['digest'] ?? null;

                if ($reported !== null) {
                    return $config['model'].'@sha256:'.$reported;
                }
            } catch (Throwable) {
                return (string) $config['model'];
            }
        }

        return (string) $config['model'];
    }
}
