<?php

namespace App\Evals\Sweep;

use InvalidArgumentException;

// Chapter 43, Topic 8: the winning learning rate, rank and epochs go into the two production configs as a reviewed change. The files are edited line by line, so their comments and layout stay and `git diff` shows exactly what changed. mlx-lm counts iterations, so its `iters` is recomputed (rows × epochs ÷ batch); the rank is written where each file keeps it, and alpha is left alone (a rank sweep holds it fixed, Appendix F.2).
final class BestSettings
{
    /** @param array{learning_rate: float|int, lora_r: int, num_epochs: int} $settings
     * @return array<string, list<string>> file => what changed */
    public function write(string $directory, array $settings, int $trainingRows, int $batchSize = 2): array
    {
        $changed = [];

        $peft = rtrim($directory, '/').'/billing-peft.yaml';

        $mlx = rtrim($directory, '/').'/billing-mlx.yaml';

        $changed['billing-peft.yaml'] = $this->edit($peft, [
            '/^(\s+learning_rate:\s*)\S+/m' => '${1}'.$this->number($settings['learning_rate']),
            '/^(\s+r:\s*)\d+/m' => '${1}'.$settings['lora_r'],
            '/^(\s+num_train_epochs:\s*)\d+/m' => '${1}'.$settings['num_epochs'],
        ]);

        $changed['billing-mlx.yaml'] = $this->edit($mlx, [
            '/^(learning_rate:\s*)\S+/m' => '${1}'.$this->number($settings['learning_rate']),
            '/^(\s+rank:\s*)\d+/m' => '${1}'.$settings['lora_r'],
            '/^(iters:\s*)\d+/m' => '${1}'.(int) ceil($trainingRows * $settings['num_epochs'] / $batchSize),
        ]);

        return $changed;
    }

    /** @param array<string, string> $replacements */
    private function edit(string $path, array $replacements): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("No production config at {$path}.");
        }

        $text = (string) file_get_contents($path);

        $changes = [];

        foreach ($replacements as $pattern => $replacement) {
            $count = 0;

            $after = (string) preg_replace($pattern, $replacement, $text, 1, $count);

            if ($count === 0) {
                throw new InvalidArgumentException("{$path} has no line matching {$pattern}: the config changed shape.");
            }

            if ($after !== $text) {
                preg_match($pattern, $text, $before);

                preg_match($pattern, $after, $now);

                $changes[] = trim($before[0]).' → '.trim($now[0]);
            }

            $text = $after;
        }

        file_put_contents($path, $text);

        return $changes;
    }

    private function number(float|int $value): string
    {
        return str_contains((string) $value, 'E') || $value < 0.001
            ? strtolower(sprintf('%.1e', $value))
            : rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
    }
}
