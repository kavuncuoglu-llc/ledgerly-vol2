<?php

namespace App\Evals;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

// Chapter 29, Figure 29-9: the golden set as promptfoo test cases. List variables are written as JSON strings because promptfoo expands a list in `vars` into one test per element.
final class PromptfooExporter
{
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(private string $rubricPath) {}

    public static function default(): self
    {
        return new self(base_path('evals/judges/support-quality.v2.yaml'));
    }

    /** @param Collection<int, GoldenCase> $cases */
    public function tests(Collection $cases, bool $withJudge): array
    {
        $assertions = $withJudge
            ? $this->judgeAssertions()
            : [];

        return $cases
            ->map(fn (GoldenCase $case) => array_filter([
                'description' => $case->id,
                'vars' => [
                    'case_id' => $case->id,
                    'question' => $case->question,
                    'customer' => $case->customer,
                    'category' => $case->category,
                    'source' => $case->source,
                    'expected_behavior' => $case->expectedBehavior,
                    'expected_chunks' => json_encode($case->expectedChunks, self::JSON),
                    'must_include' => json_encode($case->mustInclude, self::JSON),
                    'must_not_include' => json_encode($case->mustNotInclude, self::JSON),
                    'reference_answer' => $case->referenceAnswer,
                ],
                'assert' => $assertions === [] ? null : $assertions,
                'metadata' => ['source' => $case->source, 'category' => $case->category],
            ], fn ($value) => $value !== null))
            ->values()
            ->all();
    }

    public function write(string $path, Collection $cases, bool $withJudge): int
    {
        File::ensureDirectoryExists(dirname($path));

        $tests = $this->tests($cases, $withJudge);

        File::put($path, "# Written by php artisan eval:export-promptfoo from tests/golden. Do not edit; do not commit.\n".Yaml::dump($tests, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

        return count($tests);
    }

    // The rubric lives in evals/judges/support-quality.v2.yaml; only the provider is swapped for the one this machine or CI is configured with.
    private function judgeAssertions(): array
    {
        $rubric = Yaml::parseFile($this->rubricPath);

        $provider = (string) config('ai.eval.judge_provider');

        return array_map(function (array $assertion) use ($provider) {
            if ($provider !== '') {
                $assertion['provider'] = $provider;
            }

            return $assertion;
        }, $rubric['assert']);
    }
}
