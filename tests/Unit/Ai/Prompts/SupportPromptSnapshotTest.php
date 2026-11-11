<?php

use App\Ai\Prompts\SupportPrompt;
use Illuminate\Support\Carbon;

// Chapter 29, Figure 29-3: the whole rendered request, pinned. Pest's snapshot is named after the prompt's VERSION (the dataset key), so a new version is a new file in the same commit.
it('renders the support prompt exactly as reviewed', function () {
    Carbon::setTestNow('2026-11-03 09:00:00');

    $messages = SupportPrompt::messages(
        question: 'How is the late fee calculated?',
        contextChunks: [retrievedChunk()],
        history: [
            ['role' => 'user', 'content' => 'Why is my invoice overdue?'],
            ['role' => 'assistant', 'content' => 'It was due on 2 April and has not been paid.'],
        ],
    );

    expect(json_encode($messages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->toMatchSnapshot();
})->with([SupportPrompt::VERSION => [SupportPrompt::VERSION]]);
