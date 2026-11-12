# LarAgent on the support path

Status: not-now · Owner: support-AI team · Review: 2027-02-11

## Context

A teammate proposed LarAgent (`maestroerror/laragent`) for the support path: agents that feel like Eloquent models, per-user chat histories, attribute-based tools, structured output and provider fallback. It was evaluated as Chapter 48 evaluates anything new, with the rubric in Figure 48-6 and `php artisan ai:package-audit maestroerror/laragent` (below), without installing it: the audit reads Packagist's metadata and the release archive as text and runs nothing from the package.

The support path already has what LarAgent would bring. Prompts are versioned classes (`SupportPrompt`), memory is the `conversations` and `messages` tables behind `ConversationStore` (with `user:forget`, retention and the redaction chain of Chapter 34), tools are dispatched through `ToolGate` (Chapter 33), and every call goes through the decorators on `ChatModel` (Chapters 31, 34 and 36). The agent loop and the history are exactly the two things the app owns because every control hangs from them.

## Decision

Not now. LarAgent passes gates 1 and 2 and stops at gate 3 (fit): it has its own provider drivers and its own history stores, so it does not sit below `ChatModel`. Adopting it on the support path would mean one of two things: running a second memory system beside `conversations` and `messages` (two places personal data lives, two places `user:forget` must reach), or migrating the existing memory into a LarAgent custom store and giving up the decorators' view of the call, because the package would be making the provider calls itself. Neither is a small change, and the app has no feature LarAgent would make possible that `ChatModel` plus the prompt classes cannot do.

It is deferred, not rejected: it is on the quarterly audit list (`config/ai-packages.php`) with the review date above, and Chapter 50's `ai:quarterly-review` audits it again then.

## Audit

`php artisan ai:package-audit maestroerror/laragent`, run 2026-11-11 against Packagist and the 1.4.0 release archive:

| Check | Result |
| --- | --- |
| Licence | MIT, on the allowlist |
| Advisories | none on record for 1.4.0 |
| Maintenance | last release 2026-05-09, six months ago; no release in the last six months; not abandoned. The book's illustrative figure (a release three weeks ago, nine in six months) is not what Packagist shows today |
| AI dependencies | `openai-php/client` and `mozex/anthropic-php`: its provider drivers are other packages, so its health also depends on theirs |
| Stored state | no migrations and no tables of its own; six settings in `config/laragent.php` choose where state lives: `default_chat_history` (in memory), `default_history_storage` (cache, then file), `default_storage` (cache), `default_usage_storage`, a `store`, and `mcp_server_memory`. Cache and file stores are personal data; each would have to be in the data map and cleared by `user:forget` |
| Outbound | no telemetry or hosted-monitoring defaults; one provider endpoint in its config, `api.githubcopilot.com`, used only when that provider is chosen |
| Contract suite | not applicable: it is not used as a driver |
| Open items | documentation quality, team fit, golden-set behaviour, overlap with existing memory, whether the data map entry would be right, whether a quiet package is a stable one |

## What would change this

- A feature the app cannot build on `ChatModel` and the prompt classes, such as multi-agent hand-offs with a history per agent, where the package's agent model is the point and not a duplicate.
- A LarAgent driver that sits below `ChatModel` and passes `php artisan ai:contract-test`, so the decorators wrap it (it does not exist; Chapter 47's Exercise 5 is the same exercise for Neuron).
- A custom history store written over `conversations` and `messages`, with `user:forget` clearing it through the same path (Chapter 48's Exercise 2), which would remove the two-memories cost.
- Six more months of releases, or a maintenance signal that says the quiet period is stability and not capacity (Figure 47-5).
