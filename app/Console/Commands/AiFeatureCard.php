<?php

namespace App\Console\Commands;

use App\Ai\Cards\FeatureCard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class AiFeatureCard extends Command
{
    protected $signature = 'ai:feature-card
        {feature : The feature, such as support.reply}
        {--out=docs/cards/ : The directory for the internal card (<feature>.md)}
        {--public= : Where to write the public summary, such as resources/help/account/about-the-assistant.md (the help center)}
        {--check : Write nothing; fail if the committed card differs from what the app says now in the parts that do not move with traffic (the CI step)}';

    protected $description = 'Generate the feature card, internal and public, from the routes, the registry, config, the scorecard and the fairness suite (Chapter 51, Topic 5)';

    public function handle(FeatureCard $card): int
    {
        try {
            $feature = (string) $this->argument('feature');

            FeatureCard::assertFeature($feature);

            $internalPath = $this->path((string) $this->option('out'), $feature.'.md', directory: true);

            $publicPath = $this->option('public')
                ? $this->path((string) $this->option('public'), '', directory: false)
                : null;
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $today = CarbonImmutable::now()->startOfDay();

        $internal = $card->internal($feature, $today);

        $public = $card->publicSummary($feature, $today);

        if ($this->option('check')) {
            return $this->check($card, $internalPath, $internal, $publicPath, $public);
        }

        $this->write($internalPath, $internal);

        $this->line('wrote '.$this->relative($internalPath));

        if ($publicPath !== null) {
            $this->write($publicPath, $public);

            $this->line('wrote '.$this->relative($publicPath));
        }

        return self::SUCCESS;
    }

    private function check(FeatureCard $card, string $internalPath, string $internal, ?string $publicPath, string $public): int
    {
        $stale = [];

        $committed = File::exists($internalPath)
            ? $card->stableParts(File::get($internalPath))
            : [];

        foreach ($card->stableParts($internal) as $title => $text) {
            if (($committed[$title] ?? null) !== $text) {
                $stale[] = "{$title} in ".$this->relative($internalPath);
            }
        }

        if ($publicPath !== null && $this->withoutDate(File::exists($publicPath) ? File::get($publicPath) : '') !== $this->withoutDate($public)) {
            $stale[] = $this->relative($publicPath);
        }

        if ($stale === []) {
            $this->info('The feature card is current.');

            return self::SUCCESS;
        }

        $this->error('The feature card is out of date: '.implode('; ', $stale).'. Run php artisan ai:feature-card and commit the result.');

        return self::FAILURE;
    }

    private function withoutDate(string $text): string
    {
        return (string) preg_replace('/^updated: .*$/m', '', $text);
    }

    // A directory and a file name, or a file path, inside the project and nowhere else.
    private function path(string $path, string $file, bool $directory): string
    {
        $path = trim($path);

        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0") || preg_match('#^[A-Za-z0-9_./-]+$#', $path) !== 1) {
            throw new InvalidArgumentException("[{$path}] is not a path inside the project.");
        }

        if ($directory) {
            return base_path(rtrim($path, '/').'/'.$file);
        }

        if (! str_ends_with($path, '.md')) {
            throw new InvalidArgumentException("[{$path}] is not a Markdown file.");
        }

        return base_path($path);
    }

    private function write(string $path, string $text): void
    {
        File::ensureDirectoryExists(dirname($path));

        File::put($path, $text);
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/');
    }
}
