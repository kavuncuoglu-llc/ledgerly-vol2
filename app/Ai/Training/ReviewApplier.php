<?php

namespace App\Ai\Training;

use App\Ai\Privacy\PersonalDataDetector;

// Chapter 40, Topic 6: every example passes a person from the support team, who accepts, edits or rejects it. The decisions arrive as a file (the Build ships one for the seeded corpus); an example the file does not mention is accepted, because the file is the reviewer's exceptions. A second reviewer's decisions on a tenth of the examples give the agreement that goes in the manifest. An edit that brings personal data back is refused: reviewers edit text, they do not redact it.
final class ReviewApplier
{
    public const ACTIONS = ['accept', 'edit', 'reject'];

    public function __construct(private PersonalDataDetector $pii, private string $hashKey) {}

    /**
     * @param  list<array>  $joined  the dataset's records, from Dataset::joined()
     * @param  array  $file  {"reviewers": [...], "decisions": [...], "second_reviews": [...]}
     * @return array{records: list<array>, summary: array, warnings: list<string>}
     */
    public function apply(array $joined, array $file): array
    {
        $index = $this->index($joined);

        $warnings = [];

        $decisions = $this->decisions($file['decisions'] ?? [], $index, $warnings);

        $reviewer = (string) ($file['reviewers'][0] ?? 'reviewer');

        $records = [];

        $counts = ['accepted' => 0, 'edited' => 0, 'rejected' => 0];

        $reasons = [];

        foreach ($joined as $position => $record) {
            $decision = $decisions[$position] ?? ['action' => 'accept'];

            if ($decision['action'] === 'reject') {
                $counts['rejected']++;

                $reasons[$decision['reason'] ?? 'other'] = ($reasons[$decision['reason'] ?? 'other'] ?? 0) + 1;

                continue;
            }

            if ($decision['action'] === 'edit') {
                $record['completion'] = [['role' => 'assistant', 'content' => $decision['completion']]];

                $counts['edited']++;
            } else {
                $counts['accepted']++;
            }

            $record['review'] = ['action' => $decision['action'], 'reviewer' => $reviewer];

            $records[] = $record;
        }

        return ['records' => $records, 'summary' => $this->summary(count($joined), $counts, $reasons, $decisions, $file, $index, $warnings), 'warnings' => $warnings];
    }

    /** @return array<string, int> "<conversation hash>:<turn>" => position */
    private function index(array $joined): array
    {
        $index = [];

        foreach ($joined as $position => $record) {
            $index[$record['meta']['conversation'].':'.$record['meta']['turn']] = $position;
        }

        return $index;
    }

    private function position(array $decision, array $index): ?int
    {
        $conversation = (string) $decision['conversation'];

        $hash = preg_match('/^[0-9a-f]{64}$/', $conversation) === 1
            ? $conversation
            : hash_hmac('sha256', $conversation, $this->hashKey);

        return $index[$hash.':'.$decision['turn']] ?? null;
    }

    private function decisions(array $list, array $index, array &$warnings): array
    {
        $decisions = [];

        foreach ($list as $decision) {
            if (! in_array($decision['action'] ?? '', self::ACTIONS, true)) {
                $warnings[] = 'decision for '.($decision['conversation'] ?? '?').': action must be accept, edit or reject';

                continue;
            }

            $position = $this->position($decision, $index);

            if ($position === null) {
                $warnings[] = "conversation {$decision['conversation']} turn {$decision['turn']} is not in the dataset (not eligible, or not exported)";

                continue;
            }

            if ($decision['action'] === 'edit' && $this->pii->findPatterns((string) ($decision['completion'] ?? '')) !== []) {
                $warnings[] = "edit of conversation {$decision['conversation']} turn {$decision['turn']} contains personal data: refused";

                continue;
            }

            if ($decision['action'] === 'edit' && trim((string) ($decision['completion'] ?? '')) === '') {
                $warnings[] = "edit of conversation {$decision['conversation']} turn {$decision['turn']} has no completion";

                continue;
            }

            $decisions[$position] = $decision;
        }

        return $decisions;
    }

    private function summary(int $total, array $counts, array $reasons, array $decisions, array $file, array $index, array $warnings): array
    {
        $double = 0;

        $agree = 0;

        foreach ($file['second_reviews'] ?? [] as $second) {
            $position = $this->position($second, $index);

            if ($position === null) {
                continue;
            }

            $double++;

            if (($decisions[$position]['action'] ?? 'accept') === $second['action']) {
                $agree++;
            }
        }

        arsort($reasons);

        return [
            'examples' => $total,
            ...$counts,
            'rejection_reasons' => $reasons,
            'double_reviewed' => $double,
            'agreement' => $double === 0
                ? null
                : round($agree / $double, 4),
            'warnings' => count($warnings),
        ];
    }
}
