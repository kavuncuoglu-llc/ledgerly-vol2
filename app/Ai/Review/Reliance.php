<?php

namespace App\Ai\Review;

use App\Models\DraftReview;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Chapter 51, Topic 8: is review keeping pace with reliance? The share of drafts sent unedited, the time a review takes, and how often the weekly audit finds an error in a draft that was sent unedited. If unedited sends climb while the audit
// still finds errors in them, reviewers are trusting too much, and the response is coaching and interface changes, not blame.
final class Reliance
{
    public function record(string $type, int $draftId, string $draft, string $sent, int $reviewSeconds, ?int $reviewerId = null): DraftReview
    {
        return DraftReview::updateOrCreate(
            ['draft_type' => $type, 'draft_id' => $draftId],
            ['reviewer_id' => $reviewerId, 'sent_at' => now(), 'edit_distance' => $this->editDistance($draft, $sent), 'review_seconds' => max(0, $reviewSeconds)],
        );
    }

    public function audit(DraftReview $review, bool $foundError): void
    {
        $review->update(['audited' => true, 'audit_found_error' => $foundError]);
    }

    /** @return Collection<int, object{week: string, reviews: int, unedited_share: float, median_review_s: float, audited_unedited_error_rate: ?float}> */
    public function weekly(int $weeks = 12): Collection
    {
        return collect(DB::select(<<<'SQL'
            select to_char(date_trunc('week', sent_at), 'YYYY-MM-DD') as week,
                   count(*) as reviews,
                   avg((edit_distance = 0)::int) as unedited_share,
                   percentile_cont(0.5) within group (order by review_seconds) as median_review_s,
                   avg(audit_found_error::int) filter (where audited and edit_distance = 0) as audited_unedited_error_rate
            from draft_reviews
            group by 1
            order by 1 desc
            limit ?
        SQL, [max(1, $weeks)]))->map(fn ($row) => (object) [
            'week' => $row->week,
            'reviews' => (int) $row->reviews,
            'unedited_share' => (float) $row->unedited_share,
            'median_review_s' => (float) $row->median_review_s,
            'audited_unedited_error_rate' => $row->audited_unedited_error_rate === null ? null : (float) $row->audited_unedited_error_rate,
        ]);
    }

    // Characters changed between the draft and the sent reply (Levenshtein), on the first 2,000 characters of each.
    public function editDistance(string $a, string $b): int
    {
        $a = mb_substr($a, 0, 2000);

        $b = mb_substr($b, 0, 2000);

        if ($a === $b) {
            return 0;
        }

        $a = mb_str_split($a);

        $b = mb_str_split($b);

        $previous = range(0, count($b));

        foreach ($a as $i => $charA) {
            $row = [$i + 1];

            foreach ($b as $j => $charB) {
                $row[] = min($previous[$j + 1] + 1, $row[$j] + 1, $previous[$j] + ($charA === $charB ? 0 : 1));
            }

            $previous = $row;
        }

        return $previous[count($b)];
    }
}
