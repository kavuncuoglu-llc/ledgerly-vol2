<?php

namespace App\Ai\Structured;

use App\Ai\Retrieval\CitationReport;
use Illuminate\Contracts\Support\MessageBag;

final class RepairPrompt
{
    public static function for(MessageBag $errors): string
    {
        $json = $errors->toJson(JSON_PRETTY_PRINT);

        return <<<TXT
        The object you returned has validation errors:
        {$json}

        Return a corrected object matching the same schema. Change only what is needed to
        fix the errors. If a value cannot be determined from the source, use null.
        TXT;
    }

    public static function citations(CitationReport $report): string
    {
        $problems = implode("\n", array_map(fn (string $error) => '- '.$error, $report->errors));

        return <<<TXT
        Your answer has citation problems:
        {$problems}

        Write the answer again. Cite only labels that appear in the context, in brackets, after the sentence each one supports. State only what the cited passage says; drop any claim you cannot support. If the context does not contain the answer, say so in one sentence and offer to open a support ticket.
        TXT;
    }
}
