<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// Chapter 36, Topic 3: each team's AI spend for the week, by feature. Showback: enough to make cost a design consideration rather than finance's problem.
class SpendShowback extends Mailable
{
    public function __construct(public string $team, public string $since, public array $rows, public float $totalUsd) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "AI spend for {$this->team}, week of {$this->since}");
    }

    public function content(): Content
    {
        $lines = array_map(fn (array $row) => sprintf('%-24s %8s calls  $%s', $row['feature'], number_format($row['calls']), number_format($row['usd'], 2)), $this->rows);

        return new Content(htmlString: '<pre>'.e(implode("\n", $lines)."\n\n".sprintf('%-24s %8s        $%s', 'total', '', number_format($this->totalUsd, 2))).'</pre>');
    }
}
