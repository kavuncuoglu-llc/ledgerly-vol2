<?php

namespace App\Ai\Ledger;

final class UsageRows
{
    /** @return array<string, array{input_tokens: int, output_tokens: int, cost_usd: float}> */
    public static function forDay(array $rows, string $provider, string $date): array
    {
        $usage = [];

        foreach ($rows as $row) {
            if (isset($row['provider']) && $row['provider'] !== $provider) {
                continue;
            }

            if (isset($row['date']) && $row['date'] !== $date) {
                continue;
            }

            $model = (string) ($row['model'] ?? '');

            $usage[$model] ??= ['input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0];

            $usage[$model]['input_tokens'] += (int) ($row['input_tokens'] ?? 0);
            $usage[$model]['output_tokens'] += (int) ($row['output_tokens'] ?? 0);
            $usage[$model]['cost_usd'] += (float) ($row['cost_usd'] ?? 0);
        }

        return $usage;
    }
}
