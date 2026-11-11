<?php

namespace App\Ai\Quantization;

use InvalidArgumentException;

// Chapter 44, Topic 3: the fine-tune and the base at each quantization level, the golden billing slice through every pair, and the fine-tune's advantage at each level beside file size and decode speed. Like with like: a Q4_K_M fine-tune is judged against a Q4_K_M base, because that is what it replaces.
// The smallest level whose fine-tune still clears the scorecard's floor with a margin, and still holds most of its 16-bit advantage, is the one to serve.
class QuantLadder
{
    public function __construct(private LadderBackend $backend) {}

    /**
     * @param  list<string>  $levels
     * @param  list<array>  $cases  rows with `prompt`, `must_include`, `must_not_include`
     * @param  array{floor: float, margin: float, max_loss: float}  $rule
     */
    public function run(string $tunedF16, ?string $baseF16, array $levels, array $cases, ?string $calibration, string $directory, array $rule, ?callable $progress = null): LadderResult
    {
        if ($cases === []) {
            throw new InvalidArgumentException('The slice has no cases with a prompt to run.');
        }

        $tuned = $this->backend->quantize($tunedF16, $levels, $calibration, $directory.'/tuned');

        $base = $baseF16 === null
            ? []
            : $this->backend->quantize($baseF16, $levels, $calibration, $directory.'/base');

        $rows = [];

        foreach ($levels as $level) {
            $progress && $progress($level);

            $rows[$level] = [
                'bytes' => $tuned[$level]['bytes'],
                'tuned' => $this->measure("ladder-tuned-{$this->slug($level)}", $tuned[$level]['path'], $cases),
                'base' => isset($base[$level])
                    ? $this->measure("ladder-base-{$this->slug($level)}", $base[$level]['path'], $cases)
                    : null,
            ];
        }

        return $this->select($rows, $rule);
    }

    /** @return array{required_facts: float, golden_pass: float, ttft_seconds: float, decode_tps: float} */
    private function measure(string $name, string $gguf, array $cases): array
    {
        $this->backend->load($name, $gguf);

        try {
            $facts = 0;

            $wanted = 0;

            $passed = 0;

            $ttft = [];

            $tps = [];

            foreach ($cases as $case) {
                $answer = $this->backend->chat($name, $case['prompt']);

                $present = count(array_filter((array) $case['must_include'], fn (string $fact) => str_contains($answer['content'], $fact)));

                $forbidden = count(array_filter((array) ($case['must_not_include'] ?? []), fn (string $text) => str_contains($answer['content'], $text)));

                $facts += $present;

                $wanted += count((array) $case['must_include']);

                $passed += $present === count((array) $case['must_include']) && $forbidden === 0 ? 1 : 0;

                $ttft[] = $answer['ttft_seconds'];

                $tps[] = $answer['decode_tps'];
            }
        } finally {
            $this->backend->unload($name);
        }

        return [
            'required_facts' => $wanted === 0 ? 1.0 : round($facts / $wanted, 4),
            'golden_pass' => round($passed / count($cases), 4),
            'ttft_seconds' => $this->median($ttft),
            'decode_tps' => $this->median($tps),
        ];
    }

    private function select(array $rows, array $rule): LadderResult
    {
        $reference = $rows['f16'] ?? $rows[array_key_first($rows)];

        $referenceAdvantage = $reference['base'] === null
            ? null
            : $reference['tuned']['required_facts'] - $reference['base']['required_facts'];

        $passing = [];

        foreach ($rows as $level => $row) {
            if ($row['tuned']['golden_pass'] < $rule['floor'] + $rule['margin']) {
                continue;
            }

            if ($referenceAdvantage !== null && $row['base'] !== null && ($row['tuned']['required_facts'] - $row['base']['required_facts']) < $referenceAdvantage - $rule['max_loss']) {
                continue;
            }

            $passing[$level] = $row['bytes'];
        }

        if ($passing === []) {
            return new LadderResult($rows, null, "no level clears the floor of {$rule['floor']} with a margin of {$rule['margin']}".($referenceAdvantage === null ? '' : ' and keeps the fine-tune\'s advantage'));
        }

        asort($passing);

        $selected = array_key_first($passing);

        return new LadderResult($rows, $selected, 'the smallest level that clears the floor with a margin'.($referenceAdvantage === null ? ' (no base given: the advantage was not compared)' : ' and keeps the advantage'));
    }

    private function median(array $values): float
    {
        sort($values);

        $count = count($values);

        return $count === 0
            ? 0.0
            : round($values[intdiv($count, 2)], 3);
    }

    private function slug(string $level): string
    {
        return str_replace('+', '-', $level);
    }
}
