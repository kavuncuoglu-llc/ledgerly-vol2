<?php

use App\Ai\Drift\Drift;
use App\Models\AnswerFeedback;
use App\Models\Message;
use App\Models\OnlineJudgment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

// Chapter 31, Figure 31-8: drift measured from the audit trail. PSI for the mix of questions, rolling z-scores for how the assistant behaves and how good it is.
it('computes the population stability index the way the figure defines it', function () {
    expect(Drift::psi(['billing' => 0.5, 'howto' => 0.5], ['billing' => 0.5, 'howto' => 0.5]))->toBe(0.0)
        ->and(Drift::psi(['billing' => 0.52, 'howto' => 0.21, 'bug' => 0.27], ['billing' => 0.38, 'howto' => 0.33, 'bug' => 0.29]))->toBeBetween(0.09, 0.11)
        ->and(Drift::psi(['billing' => 0.9, 'howto' => 0.1], ['billing' => 0.4, 'howto' => 0.6]))->toBeGreaterThan(0.25)
        ->and(Drift::psi(['billing' => 1.0], ['billing' => 0.5, 'new' => 0.5]))->toBeGreaterThan(1.0);          // a category with no baseline share counts as tiny, not as zero
});

it('reads the rules of thumb: below 0.10 stable, 0.10 to 0.25 look, above 0.25 act', function () {
    expect(Drift::verdict(0.04))->toBe('stable')
        ->and(Drift::verdict(0.10))->toBe('look')
        ->and(Drift::verdict(0.25))->toBe('look')
        ->and(Drift::verdict(0.31))->toBe('ACT');
});

it('computes a z-score of a window against the days of a baseline', function () {
    expect(Drift::zScore(0.114, [0.06, 0.062, 0.058, 0.061, 0.059]))->toBeGreaterThan(3.0)
        ->and(Drift::zScore(0.06, [0.06, 0.06, 0.06]))->toBe(0.0)                      // no spread, no score
        ->and(Drift::zScore(0.5, [0.4]))->toBe(0.0);                                   // one day is no baseline
});

describe('ai:drift', function () {
    beforeEach(function () {
        Carbon::setTestNow('2026-11-10 12:00:00');

        [$this->user] = customerWithInvoice();

        $this->conversation = conversationFor($this->user);

        $this->seq = 0;
    });

    function drifted(object $test, string $when, string $category, string $content, int $count = 1): void
    {
        foreach (range(1, $count) as $ignored) {
            Message::create([
                'conversation_id' => $test->conversation->id, 'tenant_id' => $test->conversation->tenant_id, 'seq' => ++$test->seq, 'role' => 'assistant', 'content' => $content,
                'question' => 'q', 'status' => 'completed', 'tokens' => 5, 'category' => $category, 'created_at' => $when, 'updated_at' => $when,
            ]);
        }
    }

    it('flags a mix of questions that moved, and the decline rate that came with it', function () {
        foreach (range(10, 36) as $daysAgo) {                       // a steady baseline: 70% billing, 30% howto, almost no declines
            $day = Carbon::now()->subDays($daysAgo)->toDateTimeString();

            drifted($this, $day, 'billing', 'The fee is 1.5% a month [late-fees › How the fee is calculated].', 7);
            drifted($this, $day, 'howto', 'Open Settings and choose Export [export › Steps].', 2);
            drifted($this, $day, 'howto', "I can't find that in the Ledgerly help center, and I won't guess.", $daysAgo % 9 === 0 ? 1 : 0);
        }

        foreach (range(1, 6) as $daysAgo) {                         // the week under test: the new feature's questions, which the help center covers thinly
            $day = Carbon::now()->subDays($daysAgo)->toDateTimeString();

            drifted($this, $day, 'billing', 'The fee is 1.5% a month [late-fees › How the fee is calculated].', 2);
            drifted($this, $day, 'howto', "I can't find that in the Ledgerly help center, and I won't guess.", 6);
            drifted($this, $day, 'other', "I can't find that in the Ledgerly help center, and I won't guess.", 2);
        }

        [$code, $out] = artisanOutput('ai:drift', ['--window' => '7d', '--baseline' => '28d']);

        expect($code)->toBe(0)
            ->and($out)->toMatch('/input    category PSI [0-9.]+  ← ACT/')
            ->and($out)->toContain('howto')
            ->and($out)->toContain('decline rate')
            ->and($out)->toContain('← ALERT')
            ->and($out)->toContain('the mix of questions moved')
            ->and($out)->toContain('the decline rate is outside its range');
    });

    it('reads a stable week as stable', function () {
        foreach (range(1, 36) as $daysAgo) {
            $day = Carbon::now()->subDays($daysAgo)->toDateTimeString();

            drifted($this, $day, 'billing', 'The fee is 1.5% a month [late-fees › How the fee is calculated].', 7 + ($daysAgo % 2));
            drifted($this, $day, 'howto', 'Open Settings and choose Export [export › Steps].', 3);
        }

        [, $out] = artisanOutput('ai:drift');

        expect($out)->toMatch('/input    category PSI 0\.0\d\s/')
            ->and($out)->not->toContain('ACT')
            ->and($out)->not->toContain('suggest');
    });

    it('watches the sampled judge: a faithfulness score far under its baseline is an alert', function () {
        $reply = Message::create(['conversation_id' => $this->conversation->id, 'tenant_id' => $this->conversation->tenant_id, 'seq' => 1, 'role' => 'assistant', 'content' => 'x', 'question' => 'q', 'status' => 'completed', 'tokens' => 1, 'category' => 'billing']);

        foreach (range(10, 30) as $daysAgo) {
            $at = Carbon::now()->subDays($daysAgo);

            OnlineJudgment::create(['subject' => "message:{$daysAgo}", 'message_id' => $reply->id, 'metric' => 'faithfulness', 'score' => 0.93 + ($daysAgo % 3) / 100, 'created_at' => $at, 'updated_at' => $at]);
        }

        OnlineJudgment::create(['subject' => 'message:99', 'message_id' => $reply->id, 'metric' => 'faithfulness', 'score' => 0.55, 'created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()->subDay()]);

        [, $out] = artisanOutput('ai:drift');

        expect($out)->toContain('sampled faithfulness 0.94 → 0.55')->toContain('← ALERT')->toContain('sampled faithfulness moved');
    });

    it('watches the thumbs-down rate against its daily history', function () {
        $replies = collect(range(1, 24))->map(fn (int $i) => Message::create(['conversation_id' => $this->conversation->id, 'tenant_id' => $this->conversation->tenant_id, 'seq' => $i, 'role' => 'assistant', 'content' => 'x', 'question' => 'q', 'status' => 'completed', 'tokens' => 1]));

        foreach (range(1, 20) as $i) {
            $at = Carbon::now()->subDays(10 + $i % 15);

            AnswerFeedback::create(['message_id' => $replies[$i - 1]->id, 'tenant_id' => $replies[0]->tenant_id, 'rating' => $i % 10 === 0 ? 'down' : 'up', 'created_at' => $at, 'updated_at' => $at]);
        }

        foreach ([21, 22, 23, 24] as $i) {
            AnswerFeedback::create(['message_id' => $replies[$i - 1]->id, 'tenant_id' => $replies[0]->tenant_id, 'rating' => 'down', 'created_at' => Carbon::now()->subDay(), 'updated_at' => Carbon::now()->subDay()]);
        }

        [, $out] = artisanOutput('ai:drift');

        expect($out)->toContain('thumbs-down')->toContain('100.0%');
    });

    it('says what it has no data for, and rejects a window that is not whole days', function () {
        [$code, $out] = artisanOutput('ai:drift');

        expect($code)->toBe(0)->and($out)->toContain('no category data in both periods')->toContain('output   no data in both periods');

        expect(artisanOutput('ai:drift', ['--window' => '12h'])[0])->toBe(1);
    });

    it('is scheduled once a day', function () {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'ai:drift'));

        expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 0 * * *');
    });
});
