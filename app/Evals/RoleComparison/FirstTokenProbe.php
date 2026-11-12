<?php

namespace App\Evals\RoleComparison;

use App\Ai\AiManager;
use App\Ai\Contracts\ChatRequest;
use App\Ai\Contracts\Stream\Delta;
use Throwable;

// Chapter 47, Topic 9: the time from sending a request to the first token arriving, through the whole decorator chain, for one role. A fixed short question, a few times, the median: it measures the driver and its package and not the model (the same model answers every time), so the difference between drivers is what the package adds. The first probe is thrown away because it can include loading the model.
final class FirstTokenProbe
{
    public function __construct(private AiManager $ai) {}

    public function medianSeconds(string $provider, int $probes = 5): ?float
    {
        $times = [];

        for ($i = 0; $i <= $probes; $i++) {
            $seconds = $this->once($provider);

            if ($seconds === null) {
                return null;
            }

            if ($i > 0) {
                $times[] = $seconds;
            }
        }

        sort($times);

        return round($times[intdiv(count($times), 2)], 3);
    }

    private function once(string $provider): ?float
    {
        $request = ChatRequest::make([['role' => 'user', 'content' => 'Say the word ready.']], ['profile' => 'deterministic', 'max_tokens' => 8], 'compare.ttft');

        $started = hrtime(true);

        try {
            foreach ($this->ai->chat($provider)->stream($request) as $event) {
                if ($event instanceof Delta) {
                    return (hrtime(true) - $started) / 1e9;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
