<?php

namespace App\Ai\Routing;

use App\Ai\Streaming\StreamListener;
use Closure;

// Chapter 37, Topic 9, Figure 37-9: try the cheapest role, check the answer, and either use it or move to the next role with the same retrieved sources. The book's listing works on Question, Retrieved and Answer objects the repository does not have;
// this is the same loop over the repository's pieces. The attempt closure is SupportAssistant's own run, verify and guard for one role, so every attempt reserves against the budget like any other call and can fall to Chapter 36's ladder.
// One more thing changes the experience: an attempt that is not the last is held back (not streamed) until it has passed, then sent at once; only the last role in the chain streams.
final class CascadingAnswer
{
    public function __construct(private CascadeChecks $checks) {}

    /**
     * @param  Closure(string, int, ?StreamListener): AttemptResult  $attempt  role (a provider name), step, the listener to stream to (null while holding back)
     * @param  Closure(int, string[]): void  $record  told, for each attempt, the step and the checks it failed
     */
    public function run(RoutePlan $plan, float $bestScore, Closure $attempt, ?StreamListener $stream, Closure $record): CascadeOutcome
    {
        $chain = $plan->chainFor($bestScore);

        $steps = [];

        $last = null;

        foreach ($chain as $step => $provider) {
            $isLast = $step === count($chain) - 1;

            $held = $plan->buffers && ! $isLast;

            $last = $attempt($provider, $step, $held ? null : $stream);

            if ($last->result->pendingApprovals !== []) {
                $steps[] = ['provider' => $provider, 'failed' => []];             // a write is waiting for a person: that is the answer, whichever role asked

                return new CascadeOutcome($last, false, $steps);
            }

            $failed = $this->checks->failures($last, $bestScore);

            $steps[] = ['provider' => $provider, 'failed' => $failed];

            $record($step, $failed);

            if ($failed === []) {
                if ($held && $stream !== null) {
                    $stream->delta($last->result->answer());                       // buffered drafts are sent now, in one piece
                }

                return new CascadeOutcome($last, false, $steps);
            }
        }

        return new CascadeOutcome($last, true, $steps);                            // a person, never a failed draft
    }
}
