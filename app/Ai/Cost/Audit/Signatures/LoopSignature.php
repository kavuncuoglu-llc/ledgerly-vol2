<?php

namespace App\Ai\Cost\Audit\Signatures;

use App\Ai\Cost\Audit\AuditData;
use App\Ai\Cost\Audit\Signature;
use App\Ai\Cost\Audit\SignatureResult;
use App\Ai\Cost\Audit\Unit;

// "The agent that never stopped" (Chapter 35, Topic 3): conversations, or jobs, with far more calls than the median. Every call was ordinary; the count is the signature.
final class LoopSignature implements Signature
{
    public function name(): string
    {
        return 'loop';
    }

    public function detect(AuditData $data): SignatureResult
    {
        $units = $data->units->filter(fn (Unit $unit) => ! str_starts_with($unit->key, 'call:'));

        $median = $data->median($units->map(fn (Unit $unit) => $unit->callCount()));

        $multiple = (float) config('ai.cost.audit.loop_multiple', 3);

        $limit = max($multiple * $median, (float) config('ai.cost.audit.loop_min_calls', 6));

        $looping = $units->filter(fn (Unit $unit) => $unit->callCount() > $limit)->sortByDesc(fn (Unit $unit) => $unit->usd())->values();

        if ($looping->isEmpty()) {
            return SignatureResult::clear($this->name(), sprintf('no conversation over %s× the median of %d calls', $this->plain($multiple), $median));
        }

        $worst = $looping->max(fn (Unit $unit) => $unit->callCount());

        $suspect = $looping->groupBy(fn (Unit $unit) => $unit->feature().', prompt '.$unit->promptVersion())->sortByDesc(fn ($group) => $group->count())->keys()->first();

        return SignatureResult::flagged(
            $this->name(),
            sprintf('%d conversations with > %s× median calls (median %d, worst %d) — %s', $looping->count(), $this->plain($multiple), $median, $worst, $suspect),
            $looping->take((int) config('ai.cost.audit.examples', 3))->map(fn (Unit $unit) => sprintf('%s  %d calls  $%.2f  trace %s', $this->label($unit), $unit->callCount(), $unit->usd(), $data->trace($unit->costliest()?->traceId)))->all(),
            'the agent that made them has no round ceiling, or a tool keeps failing: check the limits in AgentBudget and the tool errors in the trace',
        );
    }

    private function label(Unit $unit): string
    {
        return $unit->conversationId !== null
            ? 'conv '.$unit->conversationId
            : 'job '.substr($unit->key, 4, 8);
    }

    private function plain(float $number): string
    {
        return rtrim(rtrim(number_format($number, 1), '0'), '.');
    }
}
