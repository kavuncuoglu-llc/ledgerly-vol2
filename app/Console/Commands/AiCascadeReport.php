<?php

namespace App\Console\Commands;

use App\Ai\Routing\CascadeReport;
use App\Ai\Routing\ExpectedCost;
use Carbon\CarbonImmutable;
use DateInterval;
use Illuminate\Console\Command;

class AiCascadeReport extends Command
{
    protected $signature = 'ai:cascade-report
        {--feature=support.reply : The feature}
        {--days=7 : The window in days}
        {--since= : The window as 12h, 7d or 4w (instead of --days)}
        {--compare= : previous-week: also read the window before this one and print what moved}
        {--conversation= : Every attempt of every question in one conversation}';

    protected $description = 'Where each category settles, why answers escalated, and what they cost against sending everything to the most capable role (Chapter 37)';

    public function handle(CascadeReport $report): int
    {
        if ($this->option('conversation') !== null) {
            return $this->conversation($report, (int) $this->option('conversation'));
        }

        $to = CarbonImmutable::now();

        $window = $this->window();

        $from = $to->sub($window);

        $current = $report->between($from, $to, (string) $this->option('feature'));

        if ($current['answers'] === 0) {
            $this->line('No cascaded answers in the window.');

            return self::SUCCESS;
        }

        $this->render($current);

        if ($this->option('compare') === 'previous-week') {
            $this->compare($current, $report->between($from->sub($window), $from, (string) $this->option('feature')));
        }

        return self::SUCCESS;
    }

    private function render(array $report): void
    {
        $this->table(
            ['category', 'start', 'settled local', 'settled mid', 'settled frontier', 'handed off', 'top escalation reason'],
            collect($report['categories'])->map(fn (array $row, string $category) => [
                $category,
                $row['start'],
                $this->share($row['settled']['local'] ?? null),
                $this->share($row['settled']['mid'] ?? null),
                $this->share($row['settled']['frontier'] ?? null),
                $this->share($row['handed_off'], dash: false),
                $row['top_reason'] ?? '—',
            ])->values()->all(),
        );

        $saving = $report['baseline_per_answer'] > 0
            ? sprintf(', %s%d%%', $report['cost_per_answer'] <= $report['baseline_per_answer'] ? '−' : '+', abs(round((1 - $report['cost_per_answer'] / $report['baseline_per_answer']) * 100)))
            : '';

        $latency = $report['extra_latency_ms'] === null
            ? ''
            : sprintf(' · added wait p50 +%s s on escalated answers', number_format($report['extra_latency_ms'] / 1000, 1));

        $this->line(sprintf('cost per answer $%s (all-frontier: $%s%s)%s', $this->money($report['cost_per_answer']), $this->money($report['baseline_per_answer']), $saving, $latency));

        $human = (float) config('ai.cascade.human_cost_per_escalation_usd');

        $this->line(sprintf(
            'total cost per conversation $%s = AI $%s + %d%% handed to a person × $%s of support time',
            $this->money(ExpectedCost::totalPerConversation($report['cost_per_answer'], $report['handed_off_share'], $human)),
            $this->money($report['cost_per_answer']),
            round($report['handed_off_share'] * 100),
            number_format($human, 2),
        ));
    }

    private function compare(array $now, array $before): void
    {
        if ($before['answers'] === 0) {
            $this->line('compare        nothing in the window before this one');

            return;
        }

        $this->line(sprintf(
            'compare        settled at the cheapest tier %d%% → %d%% · cost per answer $%s → $%s · handed off %d%% → %d%%',
            round($before['cheapest_share'] * 100),
            round($now['cheapest_share'] * 100),
            $this->money($before['cost_per_answer']),
            $this->money($now['cost_per_answer']),
            round($before['handed_off_share'] * 100),
            round($now['handed_off_share'] * 100),
        ));
    }

    private function conversation(CascadeReport $report, int $id): int
    {
        $answers = $report->conversation($id);

        if ($answers->isEmpty()) {
            $this->error("No cascaded answers in conversation {$id}.");

            return self::FAILURE;
        }

        foreach ($answers as $number => $answer) {
            $this->line(sprintf('question %d (%s): %s', $number + 1, $answer['category'], $answer['handed_off'] ? 'handed to a person' : 'settled on '.$answer['settled_role']));

            $this->table(['attempt', 'role', 'cost', 'failed checks'], collect($answer['steps'])->map(fn (array $step) => [$step['step'] + 1, $step['role'], '$'.$this->money($step['cost']), $step['failed'] ?? 'passed'])->all());
        }

        return self::SUCCESS;
    }

    private function window(): DateInterval
    {
        if (preg_match('/^(\d+)([hdw])$/', (string) $this->option('since'), $match) === 1) {
            return match ($match[2]) {
                'h' => new DateInterval('PT'.$match[1].'H'),
                'w' => new DateInterval('P'.((int) $match[1] * 7).'D'),
                default => new DateInterval('P'.$match[1].'D'),
            };
        }

        return new DateInterval('P'.max(1, (int) $this->option('days')).'D');
    }

    private function share(?float $share, bool $dash = true): string
    {
        if ($share === null) {
            return $dash ? '—' : '0%';
        }

        return round($share * 100).'%';
    }

    private function money(float $usd): string
    {
        if ($usd >= 0.1) {
            return number_format($usd, 2);
        }

        $trimmed = rtrim(rtrim(number_format($usd, 4), '0'), '.');

        return $trimmed === ''
            ? '0'
            : $trimmed;
    }
}
