<?php

namespace App\Ai\Distillation;

use App\Ai\Contracts\ChatRequest;
use App\Ai\Prompts\SupportPrompt;
use App\Ai\Retrieval\RetrievalScope;
use App\Ai\Retrieval\Retriever;
use App\Facades\Ai;

// Chapter 46, Topic 3: the teacher answers each question with what production would give it: the full long system message and the retrieved passages, so its answers are as good as the system can make them. The student is trained on the short fine-tuned prompt (Distiller), so the long prompt's instructions are distilled into the weights.
// The teacher sees no tools here: a question that needs a customer's account (an invoice, a payment) is not a question this student is distilled for, and a teacher-written question has no customer.
class TeacherAnswerer
{
    public function __construct(private Retriever $retriever) {}

    public function answer(Question $question, Teacher $teacher): Answer
    {
        $chunks = $this->retriever->retrieve($question->text, RetrievalScope::shared(), (int) config('ai.distillation.retrieve', 4));

        $messages = SupportPrompt::messages(question: $question->text, contextChunks: $chunks);

        $response = Ai::chat($teacher->role)->chat(ChatRequest::make($messages, ['profile' => 'assistant', 'max_tokens' => (int) config('ai.distillation.max_tokens', 350), 'prompt_version' => SupportPrompt::VERSION], 'dataset.distill'));

        return new Answer(trim((string) $response->content), $chunks, array_map(fn ($chunk) => $chunk->evalLabel(), $chunks), (int) ($response->usage->inputTokens ?? 0));
    }
}
