<?php

namespace App\Ai\Distillation;

use App\Ai\Privacy\PersonalDataDetector;
use App\Ai\Training\SurrogateRedactor;
use App\Models\Conversation;

// Chapter 46, Topic 3: real questions from consented conversations, redacted with surrogates as Chapter 40 does, because they carry real phrasing and real confusion. The tenant's training-use setting and the user's exclusion decide eligibility (Conversation::eligibleForTraining),
// and so does the region: a question from a tenant with a data residency goes only to a teacher that runs in that region. A question counts for a category when the answer it got cited an article of that category.
class ConsentedQuestions
{
    public function __construct(private SurrogateRedactor $redactor, private PersonalDataDetector $pii) {}

    /** @return list<Question> */
    public function collect(string $category, int $limit, ?string $teacherRegion, string $hashKey, bool $patternsOnly = false): array
    {
        $questions = [];

        Conversation::query()->eligibleForTraining($teacherRegion)->with('tenant')->lazyById(200)->each(function (Conversation $conversation) use ($category, $limit, $hashKey, $patternsOnly, &$questions) {
            foreach ($conversation->answeredTurns() as $turn) {
                if (count($questions) >= $limit) {
                    return false;
                }

                $article = $this->citedArticle($turn->retrievedLabels(), $category);

                if ($article === null) {
                    continue;
                }

                $question = new Question($this->redacted((string) $turn->question, $conversation, $turn->seq, $hashKey, $patternsOnly), Question::CONSENTED, $category, $article, hash_hmac('sha256', (string) $conversation->id, $hashKey));

                $questions[$question->id()] = $question;
            }
        });

        return array_slice(array_values($questions), 0, $limit);
    }

    private function citedArticle(array $labels, string $category): ?string
    {
        foreach ($labels as $label) {
            $path = Categories::pathOf((string) $label);

            if (Categories::covers($category, $path)) {
                return $path;
            }
        }

        return null;
    }

    private function redacted(string $text, Conversation $conversation, int $seq, string $key, bool $patternsOnly): string
    {
        $map = [];

        $this->redactor->startExample(hash_hmac('sha256', (string) $conversation->id, $key).':'.$seq);

        $spans = $patternsOnly
            ? $this->pii->findPatterns($text)
            : $this->pii->find($text);

        return $this->redactor->redact($text, $spans, $map);
    }
}
