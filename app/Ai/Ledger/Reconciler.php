<?php

namespace App\Ai\Ledger;

use App\Models\AiLedgerEntry;
use Illuminate\Support\Facades\DB;

// Chapter 36, Topic 1: the invoice is what you pay, so the ledger is compared with the provider's own figures every night. A gap means a price in the map is wrong, or some calls are not being recorded.
final class Reconciler
{
    /**
     * @return array<int, array{model: string, ledger_usd: float, provider_usd: float, gap_usd: float, gap_share: float, ledger_tokens: int, provider_tokens: int, diagnosis: string, ok: bool}>
     */
    public function compare(string $provider, string $date, ProviderUsage $usage): array
    {
        $theirs = $usage->forDay($provider, $date);

        $ours = AiLedgerEntry::query()
            ->where('provider', $provider)
            ->whereDate('occurred_at', $date)
            ->select('model', DB::raw('sum(cost_micro_usd) as micros'), DB::raw('sum(input_tokens) as input'), DB::raw('sum(output_tokens) as output'))
            ->groupBy('model')
            ->get()
            ->keyBy('model');

        $tolerance = (float) config('ai.budgets.reconcile.tolerance', 0.02);

        $rows = [];

        foreach (array_unique([...$ours->keys()->all(), ...array_keys($theirs)]) as $model) {
            $ledgerUsd = (float) ($ours[$model]->micros ?? 0) / 1_000_000;

            $providerUsd = (float) ($theirs[$model]['cost_usd'] ?? 0);

            $ledgerTokens = (int) ($ours[$model]->input ?? 0) + (int) ($ours[$model]->output ?? 0);

            $providerTokens = (int) ($theirs[$model]['input_tokens'] ?? 0) + (int) ($theirs[$model]['output_tokens'] ?? 0);

            $gap = $ledgerUsd - $providerUsd;

            $share = $providerUsd > 0
                ? abs($gap) / $providerUsd
                : ($ledgerUsd > 0 ? 1.0 : 0.0);

            $rows[] = [
                'model' => $model,
                'ledger_usd' => $ledgerUsd,
                'provider_usd' => $providerUsd,
                'gap_usd' => $gap,
                'gap_share' => $share,
                'ledger_tokens' => $ledgerTokens,
                'provider_tokens' => $providerTokens,
                'diagnosis' => $this->diagnose($model, $ours->has($model), isset($theirs[$model]), $ledgerTokens, $providerTokens, $share, $tolerance),
                'ok' => $share <= $tolerance,
            ];
        }

        return $rows;
    }

    // Same tokens, different dollars: a price is wrong. Fewer tokens in the ledger: calls were not recorded. A model on one side only: it was never priced, or the provider billed something the app never sent.
    private function diagnose(string $model, bool $inLedger, bool $inProvider, int $ledgerTokens, int $providerTokens, float $share, float $tolerance): string
    {
        if ($share <= $tolerance) {
            return 'agrees';
        }

        if (! $inLedger) {
            return 'the provider billed this model and the ledger has nothing: unrecorded calls, or another application uses the key';
        }

        if (! $inProvider) {
            return 'the ledger has this model and the provider does not: a wrong model name, or a price for a model that was not billed';
        }

        $tokenGap = $providerTokens > 0
            ? abs($ledgerTokens - $providerTokens) / $providerTokens
            : 0.0;

        return $tokenGap > $tolerance
            ? sprintf('the token counts differ by %d%%: calls are not being recorded, or are recorded twice', round($tokenGap * 100))
            : 'the tokens agree and the dollars do not: a price in config/ai-pricing.php is wrong (or cache reads are priced as input)';
    }
}
