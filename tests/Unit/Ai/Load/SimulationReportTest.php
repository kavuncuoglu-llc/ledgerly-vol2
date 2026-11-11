<?php

use App\Ai\Load\SimulationReport;

it('prints the line of Figure 24-8: users, turns, TTFT percentiles, failures, aggregate tokens per second', function () {
    $report = new SimulationReport(users: 16, turns: 30, seconds: 120.0, answered: 480, failed: 0, unfinished: 0, ttftMs: [...array_fill(0, 98, 410), 880, 880], completionTokens: 160800);

    expect($report->line())->toBe('16 users × 30 turns · TTFT p50 410 ms · p99 880 ms · 0 failures · aggregate 1,340 tok/s');
});

it('counts a reply that never finished as a failure, and says so when nothing streamed', function () {
    $report = new SimulationReport(users: 2, turns: 3, seconds: 10.0, answered: 5, failed: 0, unfinished: 1, ttftMs: [], completionTokens: 0);

    expect($report->line())->toBe('2 users × 3 turns · TTFT n/a (nothing streamed) · 1 failure · aggregate 0 tok/s')
        ->and($report->percentile(99))->toBeNull();
});
