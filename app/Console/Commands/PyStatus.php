<?php

namespace App\Console\Commands;

use App\Ai\Python\Streams;
use App\Models\PythonTask;
use Illuminate\Console\Command;

class PyStatus extends Command
{
    protected $signature = 'py:status';

    protected $description = 'Python tasks: queued, done, failed, and the length of the streams and the dead-letter stream (Chapter 27)';

    public function handle(Streams $streams): int
    {
        $counts = PythonTask::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $this->table(['tasks', 'count'], [
            ['queued', (int) ($counts[PythonTask::QUEUED] ?? 0)],
            ['done', (int) ($counts[PythonTask::DONE] ?? 0)],
            ['failed', (int) ($counts[PythonTask::FAILED] ?? 0)],
            ['queued and past their deadline', PythonTask::query()->where('status', PythonTask::QUEUED)->where('deadline_at', '<', now())->count()],
        ]);

        $tasks = (string) config('ai.python.streams.embed');

        $dead = (int) $streams->length((string) config('ai.python.streams.dead'));

        $this->table(['stream', 'length'], [
            [$tasks, $streams->length($tasks)],
            [(string) config('ai.python.streams.results'), $streams->length((string) config('ai.python.streams.results'))],
            [(string) config('ai.python.streams.dead'), $dead],
        ]);

        $this->line('delivered, not yet acknowledged by a worker: '.$streams->pending($tasks, (string) config('ai.python.worker_group')));

        if ($dead > 0) {
            $this->warn("{$dead} message(s) in the dead-letter stream: a worker gave up on them after three attempts.");
        }

        return self::SUCCESS;
    }
}
