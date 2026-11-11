<?php

namespace App\Ai\Routing;

// config/ai-routes.php, in the shape Chapter 44 reads: each category has a `default` route with a `start` and a `chain` (a `residency` route is added by hand).
final class RouteTableWriter
{
    /** @param  array<string, array>  $routes  from RouteTableBuilder */
    public function render(array $routes, array $meta): string
    {
        $lines = ['<?php', '', '// Written by `php artisan eval:route-table`: the cheapest role that clears the scorecard\'s floor for each category, from golden-set pass rates.', '// Review it like any other diff. `rates` is the evidence: the pass rate of each role that was measured.', '', 'return ['];

        $lines[] = "    '_meta' => ".$this->export($meta, 1).',';

        foreach ($routes as $category => $route) {
            $default = ['start' => $route['start'], 'chain' => $route['chain'], 'buffers' => $route['buffers'], 'cascade' => $route['cascade'], 'rates' => $route['rates'], 'why' => $route['why']];

            $lines[] = '    '.var_export((string) $category, true).' => [';
            $lines[] = "        'default' => ".$this->export($default, 2).',';
            $lines[] = '    ],';
        }

        $lines[] = '];';

        return implode("\n", $lines)."\n";
    }

    private function export(mixed $value, int $depth): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth + 1);

        $closing = str_repeat('    ', $depth);

        $isList = array_is_list($value);

        $items = [];

        foreach ($value as $key => $item) {
            $items[] = $indent.($isList ? '' : var_export($key, true).' => ').$this->export($item, $depth + 1).',';
        }

        return "[\n".implode("\n", $items)."\n{$closing}]";
    }
}
