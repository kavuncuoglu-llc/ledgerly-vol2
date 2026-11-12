<?php

namespace App\Evals\Fairness;

use App\Evals\GoldenCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

// Chapter 51, Topic 3: the base set and the variation sets, read from files in the repository. A dimension name is checked against config/fairness.php before it is ever turned into a path.
final class FairnessData
{
    public function dimensions(): array
    {
        return array_keys((array) config('fairness.dimensions'));
    }

    public function assertDimensions(array $names): array
    {
        $known = $this->dimensions();

        foreach ($names as $name) {
            if (! in_array($name, $known, true)) {
                throw new InvalidArgumentException("[{$name}] is not a fairness dimension: ".implode(', ', $known).'.');
            }
        }

        return array_values(array_unique($names));
    }

    /** @return Collection<int, GoldenCase> */
    public function base(?string $name = 'golden:fairness-150'): Collection
    {
        if ($name !== 'golden:fairness-150') {
            throw new InvalidArgumentException('--base takes golden:fairness-150, the set in '.config('fairness.base').", not [{$name}].");
        }

        $path = base_path((string) config('fairness.base'));

        if (! File::exists($path)) {
            throw new InvalidArgumentException('There is no base set yet: php artisan eval:fairness --build-base writes it.');
        }

        return collect(explode("\n", File::get($path)))
            ->filter(fn (string $line) => trim($line) !== '')
            ->map(fn (string $line) => GoldenCase::fromArray(json_decode($line, true, flags: JSON_THROW_ON_ERROR)))
            ->values();
    }

    /** @return array<string, mixed> */
    public function variationSet(string $dimension): array
    {
        $this->assertDimensions([$dimension]);

        $path = base_path((string) config('fairness.variations')."/{$dimension}.json");

        return json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
    }

    public function reviewed(string $dimension): bool
    {
        $set = $this->variationSet($dimension);

        return filled($set['reviewed']['by'] ?? null)
            && filled($set['reviewed']['on'] ?? null);
    }

    /** @return list<FairnessVariant> the control first, then one variant for each level of each dimension */
    public function variants(GoldenCase $case, array $dimensions): array
    {
        $variants = [new FairnessVariant($case, $case->id, FairnessVariant::CONTROL, FairnessVariant::CONTROL)];

        foreach ($dimensions as $dimension) {
            $set = $this->variationSet($dimension);

            foreach (array_keys($set['levels']) as $level) {
                $question = $this->questionFor($case, $dimension, (string) $level, $set);

                if ($question === null) {
                    continue;
                }

                $variants[] = new FairnessVariant($this->copy($case, $question, $dimension, (string) $level), $case->id, $dimension, (string) $level);
            }
        }

        return $variants;
    }

    /** @return array<string, array<string, string>> base case id => level => the rewrite */
    public function rewrites(): array
    {
        $path = base_path((string) config('fairness.variations').'/rewrites.jsonl');

        if (! File::exists($path)) {
            return [];
        }

        $rewrites = [];

        foreach (explode("\n", File::get($path)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

            $rewrites[(string) $row['id']] = (array) $row['rewrites'];
        }

        return $rewrites;
    }

    private function questionFor(GoldenCase $case, string $dimension, string $level, array $set): ?string
    {
        if (isset($set['template'])) {
            $values = (array) $set['levels'][$level];

            $value = $values[crc32($case->id.'|'.$level) % count($values)];

            return strtr($set['template'], ['{question}' => $case->question, '{value}' => $this->clean((string) $value)]);
        }

        $rewrite = $this->rewrites()[$case->id][$dimension === 'english' ? $level : $level] ?? null;

        return $rewrite === null
            ? null
            : $this->clean($rewrite);
    }

    private function copy(GoldenCase $case, string $question, string $dimension, string $level): GoldenCase
    {
        $row = $case->toArray();

        $row['id'] = "{$case->id}~{$dimension}.{$level}";

        $row['question'] = $question;

        return GoldenCase::fromArray($row);
    }

    private function clean(string $text): string
    {
        return trim((string) preg_replace('/[^\P{C}\n]+/u', '', $text));
    }
}
