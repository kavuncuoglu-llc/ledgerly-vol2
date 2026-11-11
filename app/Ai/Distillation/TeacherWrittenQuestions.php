<?php

namespace App\Ai\Distillation;

use App\Evals\Generators\QuestionWriter;
use App\Models\HelpChunk;
use Throwable;

// Chapter 46, Topic 3: "for every help-center article in a target category, the teacher writes a few questions a customer might ask, which reaches topics that real traffic hasn't yet". The teacher writes the wording from the article's own passages (Chapter 29's QuestionWriter); the facts are the article's, never the teacher's.
class TeacherWrittenQuestions
{
    /** @return list<Question> */
    public function collect(string $category, int $limit, Teacher $teacher, int $perArticle): array
    {
        $writer = new QuestionWriter($teacher->role);

        $questions = [];

        foreach ($this->articles($category) as $path => $chunks) {
            for ($i = 0; $i < $perArticle; $i++) {
                if (count($questions) >= $limit) {
                    return array_values($questions);
                }

                $chunk = $chunks[$i % count($chunks)];

                try {
                    $text = $writer->ask('Write a question a customer might ask that the passage below answers. Use the customer\'s words, not the article\'s headings.', "Help-center article {$path}, section \"{$chunk->heading}\":\n{$chunk->content}");
                } catch (Throwable) {
                    continue;                                                       // a teacher that cannot write one question does not stop the batch
                }

                $question = new Question($text, Question::TEACHER_WRITTEN, $category, $path);

                $questions[$question->id()] = $question;
            }
        }

        return array_values($questions);
    }

    /** @return array<string, list<HelpChunk>> article path => its chunks in order, for the category's articles */
    private function articles(string $category): array
    {
        $articles = [];

        foreach (HelpChunk::query()->orderBy('source')->orderBy('position')->get() as $chunk) {
            $path = Categories::pathOf((string) $chunk->source);

            if (Categories::covers($category, $path)) {
                $articles[$path][] = $chunk;
            }
        }

        ksort($articles);

        return $articles;
    }
}
