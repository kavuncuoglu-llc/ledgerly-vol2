<?php

namespace App\Console\Commands;

use App\Models\CachedAnswer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CachedAnswersReview extends Command
{
    protected $signature = 'cached-answers:review
        {--approve=* : Ids to approve (a human has read the answer; it no longer expires)}
        {--reject=* : Ids to reject (never served again)}
        {--status=auto : Which answers to list: auto, approved or rejected}
        {--limit=25 : How many to list}';

    protected $description = 'List cached answers that no one has reviewed, and approve or reject them';

    public function handle(): int
    {
        $approved = CachedAnswer::whereKey($this->option('approve'))->update(['status' => 'approved', 'expires_at' => null]);
        $rejected = CachedAnswer::whereKey($this->option('reject'))->update(['status' => 'rejected']);

        if ($approved + $rejected > 0) {
            $this->info("Approved {$approved}, rejected {$rejected}.");
        }

        $this->list();

        return self::SUCCESS;
    }

    private function list(): void
    {
        $answers = CachedAnswer::query()
            ->select(['id', 'category', 'question', 'answer', 'status', 'hit_count', 'help_version'])
            ->where('status', $this->option('status'))
            ->orderByDesc('hit_count')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($answers->isEmpty()) {
            $this->line("No {$this->option('status')} answers to review.");

            return;
        }

        foreach ($answers as $answer) {
            $this->line(sprintf('#%d  %s  %d hits  %s', $answer->id, $answer->category, $answer->hit_count, $answer->question));
            $this->line('    '.Str::limit(preg_replace('/\s+/', ' ', $answer->answer), 160));
        }

        $this->newLine();
        $this->line('Approve with --approve=ID, reject with --reject=ID (repeatable).');
    }
}
