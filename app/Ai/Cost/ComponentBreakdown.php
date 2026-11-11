<?php

namespace App\Ai\Cost;

use App\Models\ModelCall;
use Carbon\CarbonInterface;

// Chapter 37, Topic 1, Figure 37-1: find the money first. ContextAssembler records the size of every section of the prompt on each call (model_calls.context_breakdown); multiply each by the price that applied to it, cached or not, input or output,
// and a call's cost splits into its parts. The book names the meta key `components`; the repository has called it `context_breakdown` since Chapter 13. Sections are cached in the order they appear in the prompt, stable first, so the cached tokens
// of a call are charged to the system message first, then whatever comes next. The tool definitions and the format overhead are what the provider counted beyond the sections.
final class ComponentBreakdown
{
    private const ORDER = ['system', 'memories', 'summary', 'history', 'context', 'question'];

    public function __construct(private PriceMap $prices) {}

    /**
     * @param  ?string  $priceAs  price every call as this model (the "what would this cost on the frontier model" view); null prices each call by its own model
     * @return array{rows: array<int, array{component: string, tokens: int, priced_as: string, micros: int}>, calls: int, total_micros: int, total_tokens: int}
     */
    public function of(string $feature, CarbonInterface $since, ?string $priceAs = null): array
    {
        $totals = [];

        $calls = 0;

        foreach (ModelCall::query()->where('feature', $feature)->where('created_at', '>=', $since)->whereNotNull('context_breakdown')->where('prompt_tokens', '>', 0)->cursor() as $call) {
            $price = $priceAs === null
                ? $this->prices->find($call->provider, $call->model)
                : $this->prices->find('frontier', $priceAs);

            if ($price === null) {
                continue;                                                          // an unpriced model has no cost to split (ai:cost-audit says so loudly)
            }

            $calls++;

            foreach ($this->parts($call, $price) as $name => [$tokens, $micros, $as]) {
                $totals[$name] ??= ['component' => $name, 'tokens' => 0, 'priced_as' => $as, 'micros' => 0];

                $totals[$name]['tokens'] += $tokens;
                $totals[$name]['micros'] += $micros;
            }
        }

        $rows = array_values($this->ordered($totals));

        return ['rows' => $rows, 'calls' => $calls, 'total_micros' => (int) array_sum(array_column($rows, 'micros')), 'total_tokens' => (int) array_sum(array_column($rows, 'tokens'))];
    }

    /** @return array<string, array{0: int, 1: int, 2: string}> component => tokens, micro-dollars, how it was priced */
    private function parts(ModelCall $call, array $price): array
    {
        $slots = array_map('intval', $call->context_breakdown ?? []);

        $counted = array_sum($slots);

        $input = (int) $call->prompt_tokens;

        $scale = $counted > 0
            ? min(1.0, $input / $counted)
            : 1.0;                                                                 // the app's counter and the provider's can differ: the sections are scaled to what was billed

        $sections = [];

        foreach (self::ORDER as $name) {
            if (isset($slots[$name])) {
                $sections[$name] = (int) round($slots[$name] * $scale);
            }
        }

        $sections['overhead'] = max(0, $input - array_sum($sections));

        $cached = min((int) $call->cached_input_tokens, $input);

        $perM = (float) ($price['per'] ?? 1_000_000);

        $parts = [];

        foreach ($sections as $name => $tokens) {
            $hit = min($cached, $tokens);

            $cached -= $hit;

            $uncached = $tokens - $hit;

            $key = $this->label($name);

            if ($hit > 0) {
                $this->add($parts, $key.', cached part', $hit, $hit * ($price['cache_read'] ?? $price['input']) / $perM * 1_000_000, 'cache read');
            }

            if ($uncached > 0) {
                $this->add($parts, $key, $uncached, $uncached * $price['input'] / $perM * 1_000_000, 'input');
            }
        }

        $this->add($parts, 'The answer', (int) $call->completion_tokens, (int) $call->completion_tokens * $price['output'] / $perM * 1_000_000, 'output');

        return $parts;
    }

    private function add(array &$parts, string $name, int $tokens, float $micros, string $as): void
    {
        $parts[$name] = [($parts[$name][0] ?? 0) + $tokens, (int) round(($parts[$name][1] ?? 0) + $micros), $as];
    }

    private function label(string $section): string
    {
        return match ($section) {
            'system' => 'System message',
            'memories' => 'Memories',
            'summary' => 'Summary',
            'history' => 'Recent history',
            'context' => 'Retrieved sources',
            'question' => 'The question',
            default => 'Tool definitions and overhead',
        };
    }

    private function ordered(array $totals): array
    {
        $order = ['System message', 'Memories', 'Summary', 'Recent history', 'Retrieved sources', 'The question', 'Tool definitions and overhead', 'The answer'];

        uksort($totals, function (string $a, string $b) use ($order) {
            $rank = fn (string $name) => (int) array_search(explode(',', $name)[0], $order, true);

            return [$rank($a), str_contains($a, 'cached part') ? 0 : 1] <=> [$rank($b), str_contains($b, 'cached part') ? 0 : 1];
        });

        return $totals;
    }
}
