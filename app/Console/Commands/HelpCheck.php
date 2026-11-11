<?php

namespace App\Console\Commands;

use App\Help\HelpArticle;
use Illuminate\Console\Command;

class HelpCheck extends Command
{
    protected $signature = 'help:check';

    protected $description = 'Validate the front matter of every help article under resources/help';

    public function handle(): int
    {
        $articles = HelpArticle::all();
        $problems = $this->collectProblems($articles->all());

        $this->line('Help path: '.HelpArticle::basePath());
        $this->line($articles->count().' '.str('article')->plural($articles->count()).' in '.HelpArticle::categories()->count().' categories');

        foreach (HelpArticle::categories() as $category) {
            $this->line(sprintf('  %-24s %d', $category['slug'], $category['articles']->count()));
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        if ($problems !== []) {
            $this->error(count($problems).' problem(s) found.');

            return self::FAILURE;
        }

        $this->info('All articles have valid front matter.');

        return self::SUCCESS;
    }

    private function collectProblems(array $articles): array
    {
        $problems = [];

        foreach ($articles as $article) {
            foreach ($article->missingFrontMatterKeys() as $key) {
                $problems[] = "{$article->source()}: front matter is missing '{$key}'";
            }

            if (isset($article->frontMatter['category']) && ! $article->declaresMatchingCategory()) {
                $problems[] = "{$article->source()}: front matter category '{$article->frontMatter['category']}' does not match its directory";
            }

            if (trim($article->body) === '') {
                $problems[] = "{$article->source()}: the body is empty";
            }

            if (preg_match('/^#\s+/m', $article->body)) {
                $problems[] = "{$article->source()}: the body repeats an H1 heading";
            }
        }

        return $problems;
    }
}
