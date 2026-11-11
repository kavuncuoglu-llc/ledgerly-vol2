<?php

namespace App\Ai\Training;

// Chapter 40, Topic 9: four scans for what an attacker could have planted in a source nobody at Ledgerly wrote, plus the leaked-placeholder check of Appendix I.5. They run on records (a row and its metadata) and say which rows to look at; none of them deletes anything.
final class TrainingScan
{
    public const CHECKS = ['injection', 'rare-sequences', 'unsourced-links', 'concentration', 'placeholders'];

    public function __construct(
        private InjectionDetector $injection,
        private float $tenantCap = 0.05,
        private float $userCap = 0.01,
        private int $rareMinRows = 5,
        private int $rareMinTenants = 2,
        private int $rareMaxTenants = 3,
    ) {}

    /**
     * @param  list<array>  $records  prompt, completion and meta
     * @param  list<string>  $checks
     */
    public function run(array $records, array $checks = self::CHECKS): ScanReport
    {
        $results = [];

        foreach ($checks as $check) {
            $results[$check] = match ($check) {
                'injection' => $this->injection($records),
                'rare-sequences' => $this->rareSequences($records),
                'unsourced-links' => $this->unsourced($records),
                'concentration' => $this->concentration($records),
                'placeholders' => $this->placeholders($records),
            };
        }

        return new ScanReport($results);
    }

    private function injection(array $records): array
    {
        $flagged = [];

        foreach ($records as $record) {
            if ($this->injection->matches($this->customerText($record)) !== [] || $this->injection->matches($this->reply($record)) !== []) {
                $flagged[] = $record['id'];
            }
        }

        return ['flagged' => $flagged, 'detail' => [], 'line' => sprintf('%-18s %d rows flagged (instructions to the assistant inside customer text or a reply) → sent to review', 'injection', count($flagged))];
    }

    private function rareSequences(array $records): array
    {
        $rows = [];

        $tenants = [];

        foreach ($records as $record) {
            foreach ($this->phrases($this->customerText($record)) as $phrase) {
                $rows[$phrase][] = $record['id'];

                $tenants[$phrase][$record['meta']['tenant']] = true;
            }
        }

        $suspects = [];

        foreach ($rows as $phrase => $ids) {
            if (count($ids) >= $this->rareMinRows && count($tenants[$phrase]) >= $this->rareMinTenants && count($tenants[$phrase]) <= $this->rareMaxTenants && ! $this->everywhere($phrase, $records)) {
                $suspects[$phrase] = ['rows' => array_values(array_unique($ids)), 'accounts' => count($tenants[$phrase])];
            }
        }

        $suspects = $this->outermost($suspects);

        $flagged = array_values(array_unique(array_merge([], ...array_column($suspects, 'rows'))));

        $first = array_key_first($suspects);

        return [
            'flagged' => $flagged,
            'detail' => $suspects,
            'line' => $first === null
                ? sprintf('%-18s none', 'rare sequences')
                : sprintf('%-18s %d phrase%s in %d rows from %d accounts ("%s") → rows held; accounts noted', 'rare sequences', count($suspects), count($suspects) === 1 ? '' : 's', count($flagged), max(array_column($suspects, 'accounts')), $first),
        ];
    }

    private function unsourced(array $records): array
    {
        $links = [];

        $details = [];

        foreach ($records as $record) {
            $shown = strtolower($this->promptText($record));

            foreach ($this->contactsIn($this->reply($record)) as $kind => $values) {
                foreach ($values as $value) {
                    if (! str_contains($shown, strtolower($value))) {
                        $links[] = $record['id'];

                        $details[$kind] = ($details[$kind] ?? 0) + 1;
                    }
                }
            }
        }

        return ['flagged' => array_values(array_unique($links)), 'detail' => $details, 'line' => sprintf('%-18s %d · unsourced contact details %d → sent to review', 'unsourced links', $details['link'] ?? 0, ($details['email'] ?? 0) + ($details['phone'] ?? 0))];
    }

    private function concentration(array $records): array
    {
        $total = max(1, count($records));

        $tenant = array_count_values(array_column(array_column($records, 'meta'), 'tenant'));

        $user = array_count_values(array_column(array_column($records, 'meta'), 'user'));

        unset($tenant[''], $user['']);

        $largestTenant = $tenant === [] ? 0.0 : max($tenant) / $total;

        $largestUser = $user === [] ? 0.0 : max($user) / $total;

        $flagged = [];

        foreach ($records as $record) {
            $meta = $record['meta'];

            if (($tenant[$meta['tenant']] ?? 0) / $total > $this->tenantCap || ($user[$meta['user']] ?? 0) / $total > $this->userCap) {
                $flagged[] = $record['id'];
            }
        }

        return [
            'flagged' => $flagged,
            'detail' => ['largest_tenant' => round($largestTenant, 4), 'largest_user' => round($largestUser, 4), 'tenant_cap' => $this->tenantCap, 'user_cap' => $this->userCap],
            'line' => sprintf('%-18s largest tenant %.1f%% · largest user %.1f%% (caps %s%% and %s%%)', 'concentration', $largestTenant * 100, $largestUser * 100, round($this->tenantCap * 100, 1), round($this->userCap * 100, 1)),
        ];
    }

    private function placeholders(array $records): array
    {
        $flagged = [];

        foreach ($records as $record) {
            if (preg_match('/<[A-Z]+_\d+>/', json_encode($record['prompt']).$this->reply($record)) === 1) {
                $flagged[] = $record['id'];
            }
        }

        return ['flagged' => $flagged, 'detail' => [], 'line' => sprintf('%-18s %d rows with a leaked placeholder (redaction went wrong)', 'placeholders', count($flagged))];
    }

    private function customerText(array $record): string
    {
        $text = '';

        foreach ($record['prompt'] as $message) {
            if (($message['role'] ?? '') === 'user' && is_string($message['content'] ?? null) && str_contains($message['content'], 'source="customer_message"')) {
                $text .= ' '.strip_tags(str_replace('Question:', '', $message['content']));
            }

            if (($message['role'] ?? '') === 'user' && is_string($message['content'] ?? null) && ! str_contains($message['content'], 'source=')) {
                $text .= ' '.$message['content'];
            }
        }

        return $text;
    }

    private function reply(array $record): string
    {
        return (string) ($record['completion'][0]['content'] ?? '');
    }

    private function promptText(array $record): string
    {
        return implode("\n", array_map(fn (array $message) => (string) ($message['content'] ?? '').json_encode($message['tool_calls'] ?? ''), $record['prompt']));
    }

    /** @return list<string> every run of three or four words, lowercased */
    private function phrases(string $text): array
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        $found = [];

        foreach ([3, 4] as $size) {
            for ($i = 0; $i + $size <= count($words); $i++) {
                $run = array_slice($words, $i, $size);

                if (count(array_filter($run, 'ctype_digit')) > 1) {
                    continue;                                                  // a run of amounts and dates is data, not a phrase
                }

                $found[implode(' ', $run)] = true;
            }
        }

        return array_keys($found);
    }

    // A phrase every account uses is just language. A trigger is rare in the whole set and common in a few accounts' rows.
    private function everywhere(string $phrase, array $records): bool
    {
        $accounts = [];

        foreach ($records as $record) {
            if (str_contains(strtolower($this->customerText($record)), $phrase)) {
                $accounts[$record['meta']['tenant']] = true;
            }
        }

        return count($accounts) > $this->rareMaxTenants;
    }

    // "per clause 14b" and "clause 14b override" are one suspect: of the phrases found in exactly the same rows, keep the longest.
    private function outermost(array $suspects): array
    {
        uksort($suspects, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        $kept = [];

        foreach ($suspects as $phrase => $detail) {
            $signature = implode(',', $detail['rows']);

            foreach ($kept as $other) {
                if ($other['signature'] === $signature || array_diff($detail['rows'], $other['rows']) === []) {
                    continue 2;
                }
            }

            $kept[$phrase] = $detail + ['signature' => $signature];
        }

        return array_map(fn (array $detail) => array_diff_key($detail, ['signature' => 1]), $kept);
    }

    /** @return array<string, list<string>> */
    private function contactsIn(string $text): array
    {
        preg_match_all('~https?://[^\s)\]]+~i', $text, $links);

        preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $emails);

        preg_match_all('/\+?\d[\d\s().-]{8,}\d/', $text, $phones);

        return ['link' => $links[0], 'email' => $emails[0], 'phone' => $phones[0]];
    }
}
