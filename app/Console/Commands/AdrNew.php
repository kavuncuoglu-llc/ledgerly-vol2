<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AdrNew extends Command
{
    public const DECISIONS = ['adopt', 'wrap', 'wait', 'not-now', 'reject'];

    protected $signature = 'adr:new
        {title : The decision, such as "AI packages behind ModelClient"}
        {--decision=adopt : adopt, wrap, wait, not-now or reject}
        {--review= : The date the decision is read again (YYYY-MM-DD); default 90 days from today, and always set for wait and not-now}
        {--owner=platform team : Who answers for it}
        {--slug= : The file name under docs/adr (default: the title in kebab-case)}
        {--from= : eval:last or the path of an eval:compare result: written as a Measurements section by adr:append}';

    protected $description = 'Start a decision record from the skeleton the others follow, with its review date (Chapters 47 and 48)';

    public function handle(): int
    {
        $title = trim((string) preg_replace('/\s+/', ' ', (string) $this->argument('title')));

        if ($title === '') {
            $this->error('Give the decision a title.');

            return self::FAILURE;
        }

        if (mb_strlen($title) > 120) {
            $this->error('A title is a short sentence: 120 characters at most.');

            return self::FAILURE;
        }

        $decision = (string) $this->option('decision');

        if (! in_array($decision, self::DECISIONS, true)) {
            $this->error("[{$decision}] is not a decision: ".implode(', ', self::DECISIONS).'.');

            return self::FAILURE;
        }

        $slug = (string) ($this->option('slug') ?: Str::slug($title));

        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) !== 1) {
            $this->error("[{$slug}] is not a file name: lowercase letters, digits and single hyphens.");

            return self::FAILURE;
        }

        $review = $this->review();

        if ($review === null) {
            $this->error('--review is not a date: YYYY-MM-DD.');

            return self::FAILURE;
        }

        $path = base_path("docs/adr/{$slug}.md");

        if (is_file($path)) {
            $this->error("docs/adr/{$slug}.md exists: a decision is superseded by a new record, not rewritten.");

            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));

        File::put($path, $this->skeleton($title, $decision, (string) $this->option('owner'), $review));

        $this->line("  wrote docs/adr/{$slug}.md · review {$review}");

        if ($this->option('from') !== null) {
            return $this->call('adr:append', ['record' => "docs/adr/{$slug}.md", '--section' => 'Measurements', '--from' => (string) $this->option('from')]);
        }

        return self::SUCCESS;
    }

    private function review(): ?string
    {
        $given = (string) $this->option('review');

        if ($given === '') {
            return now()->addDays(90)->toDateString();
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $given) !== 1) {
            return null;
        }

        return checkdate((int) substr($given, 5, 2), (int) substr($given, 8, 2), (int) substr($given, 0, 4))
            ? $given
            : null;
    }

    private function skeleton(string $title, string $decision, string $owner, string $review): string
    {
        $owner = trim((string) preg_replace('/\s+/', ' ', $owner));

        return implode("\n", [
            "# {$title}",
            '',
            "Status: {$decision} · Owner: {$owner} · Review: {$review}",
            '',
            '## Context',
            '',
            'What was proposed, and what the app already has.',
            '',
            '## Decision',
            '',
            "The decision ({$decision}), in a sentence, and where it applies.",
            '',
            '## What would change this',
            '',
            'The evidence that would reopen it before the review date.',
            '',
        ]);
    }
}
