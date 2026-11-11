<?php

namespace App\Ai\Profile;

// Lines the samples and the token times up on the same clock, one row a second (Figure 21-9).
final class Timeline
{
    /**
     * @param  list<PromptTrace>  $traces
     * @param  list<MemorySample>  $samples
     */
    public static function build(float $startedAt, float $loadedAt, array $traces, array $samples): Profile
    {
        $endedAt = $traces === []
            ? $loadedAt
            : end($traces)->lastDeltaAt;

        $rows = [];

        for ($second = 0; $startedAt + $second <= $endedAt; $second++) {
            $rows[] = self::row($second, $startedAt, $loadedAt, $traces, $samples);
        }

        return new Profile(
            rows: $rows,
            loadToFirstToken: $traces === [] ? null : $traces[0]->firstDeltaAt - $startedAt,
            prefillRate: self::prefillRate($traces),
            decodeRate: self::decodeRate($traces),
            peakGb: self::peak($samples),
        );
    }

    private static function row(int $second, float $startedAt, float $loadedAt, array $traces, array $samples): ProfileRow
    {
        $from = $startedAt + $second;
        $to = $from + 1;
        $sample = self::sampleAt($samples, $from);

        [$phase, $prefill, $decode, $context] = self::phase($from, $to, $loadedAt, $traces);

        return new ProfileRow($second, $sample?->residentGb, $sample?->gpuGb, $phase, $prefill, $decode, $context);
    }

    /** @return array{0: string, 1: ?float, 2: ?float, 3: int} */
    private static function phase(float $from, float $to, float $loadedAt, array $traces): array
    {
        if ($to <= $loadedAt) {
            return ['load', null, null, 0];
        }

        foreach ($traces as $trace) {
            if ($to <= $trace->sentAt) {
                continue;
            }

            if ($from >= $trace->lastDeltaAt) {
                continue;
            }

            if ($to <= $trace->firstDeltaAt) {
                return ['prefill', self::rate($trace->promptTokens, $trace->prefillSeconds()), null, $trace->promptTokens];
            }

            $inWindow = count(array_filter($trace->deltaTimes, fn (float $at) => $at >= $from && $at < $to));
            $soFar = count(array_filter($trace->deltaTimes, fn (float $at) => $at < $to));

            return ['decode', null, (float) $inWindow, $trace->promptTokens + $soFar];
        }

        return ['idle', null, null, 0];
    }

    private static function sampleAt(array $samples, float $moment): ?MemorySample
    {
        $found = null;

        foreach ($samples as $sample) {
            if ($sample->at <= $moment) {
                $found = $sample;
            }
        }

        return $found ?? ($samples[0] ?? null);
    }

    private static function prefillRate(array $traces): ?float
    {
        $tokens = array_sum(array_map(fn (PromptTrace $trace) => $trace->promptTokens, $traces));
        $seconds = array_sum(array_map(fn (PromptTrace $trace) => $trace->prefillSeconds(), $traces));

        return self::rate($tokens, $seconds);
    }

    private static function decodeRate(array $traces): ?float
    {
        $tokens = array_sum(array_map(fn (PromptTrace $trace) => max(0, $trace->generated() - 1), $traces));
        $seconds = array_sum(array_map(fn (PromptTrace $trace) => $trace->decodeSeconds(), $traces));

        return self::rate($tokens, $seconds);
    }

    private static function peak(array $samples): ?float
    {
        $values = array_filter(array_map(fn (MemorySample $sample) => $sample->residentGb ?? $sample->gpuGb, $samples), fn ($value) => $value !== null);

        if ($values === []) {
            return null;
        }

        return max($values);
    }

    private static function rate(int $tokens, float $seconds): ?float
    {
        if ($tokens <= 0) {
            return null;
        }

        if ($seconds <= 0.0) {
            return null;
        }

        return $tokens / $seconds;
    }
}
