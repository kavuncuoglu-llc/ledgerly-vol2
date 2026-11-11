<?php

namespace App\Ai\Distillation;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\JudgePrompt;
use App\Evals\EvalBehavior;
use App\Evals\Gates;
use App\Facades\Ai;
use Throwable;

// Chapter 46, Topic 3: "the filter is the step that matters most". Every teacher answer runs through Chapter 29's gates (citations, length and format, the never-gates) and then the judge, and only the answers that pass both go forward,
// so the student is not trained on the teacher's mistakes. An answer that was given passages and cites none is a mistake too: the student must learn to say where an answer came from. A judge that cannot answer rejects: a doubtful answer never gets in because a check failed to run.
class AnswerFilter
{
    public function verdict(Question $question, Answer $answer, bool $useGates = true, bool $useJudge = true): Verdict
    {
        if ($answer->text === '') {
            return Verdict::gates('the teacher returned nothing');
        }

        if ($useGates) {
            $report = Gates::evaluate($answer->text, $answer->labels);

            if (! $report->passedAll()) {
                return Verdict::gates($report->describe());
            }

            if ($answer->labels !== [] && EvalBehavior::classify($answer->text) === 'answer' && preg_match('/\[[^\]]+\]/', $answer->text) !== 1) {
                return Verdict::gates('it was given sources and cites none of them');
            }
        }

        if (! $useJudge) {
            return Verdict::kept();
        }

        [$score, $why] = $this->score($question, $answer);

        if ($score === null) {
            return Verdict::judge("the judge gave no score ({$why})");
        }

        return $score >= (int) config('ai.distillation.judge_floor', 4)
            ? Verdict::kept()
            : Verdict::judge("the judge scored it {$score}");
    }

    /** @return array{0: ?int, 1: string} the score, and why there is none */
    private function score(Question $question, Answer $answer): array
    {
        $provider = (string) config('ai.compare.judge_provider', 'local');

        try {
            $reply = Ai::chat($provider)->chat(ChatRequest::make(JudgePrompt::messages($question->text, $answer->text, $answer->labels), ['profile' => 'deterministic', 'max_tokens' => 4], 'dataset.distill.judge'));

            $content = (string) $reply->content;

            return [JudgePrompt::parse($content), 'it answered "'.mb_strimwidth($content, 0, 40, '…').'"'];
        } catch (Throwable $e) {
            return [null, class_basename($e).': '.mb_strimwidth($e->getMessage(), 0, 100, '…')];
        }
    }
}
