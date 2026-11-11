<?php

namespace App\Ai\Budgets;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

// Chapter 36, Topics 5 and 7: who is told. A team (the owner of a feature), a tenant's admins, the on-call engineer and the team channel. Mail goes through the app's mailer and the channel through a webhook (a Slack-compatible incoming webhook);
// with neither configured the message goes to the log, so a development machine shows what would have been sent.
final class Notifier
{
    public function team(string $team, string $subject, string $body): void
    {
        $this->mail((array) config("ai.teams.{$team}.email"), $subject, $body);

        $this->channel("[{$team}] {$subject}\n{$body}");
    }

    public function tenantAdmins(int $tenantId, string $subject, string $body): void
    {
        $addresses = User::query()->where('tenant_id', $tenantId)->whereIn('role', ['owner', 'admin'])->pluck('email')->all();

        $this->mail($addresses, $subject, $body);
    }

    public function oncall(string $subject, string $body): void
    {
        $this->mail((array) config('ai.budgets.notify.oncall_email'), '[PAGE] '.$subject, $body);

        $this->channel("[PAGE] {$subject}\n{$body}");
    }

    public function channel(string $text): void
    {
        $webhook = config('ai.budgets.notify.channel_webhook');

        Log::warning('AI spend notice', ['text' => $text]);

        if (blank($webhook)) {
            return;
        }

        try {
            Http::timeout(3)->post($webhook, ['text' => $text]);
        } catch (Throwable $e) {
            Log::error('The team channel did not take the spend notice', ['error' => $e->getMessage()]);       // a notice that cannot be delivered must never fail the call that raised it
        }
    }

    private function mail(array $addresses, string $subject, string $body): void
    {
        $addresses = array_values(array_filter($addresses));

        if ($addresses === []) {
            return;
        }

        try {
            Mail::raw($body, fn ($message) => $message->to($addresses)->subject($subject));
        } catch (Throwable $e) {
            Log::error('A spend notice could not be mailed', ['error' => $e->getMessage()]);
        }
    }
}
