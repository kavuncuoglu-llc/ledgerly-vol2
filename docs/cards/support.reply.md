# Feature card: support.reply · generated 2026-11-12 from app commit d7c8856

## Purpose
Answers customers' questions about billing, plans, how-to, and accounts, from Ledgerly's help center and the customer's own records.
Not for: legal or tax advice; changing account data without approval; anything involving a customer's safety (routed to a person).

Reviewed each quarter with the quarterly review (`php artisan ai:quarterly-review`).

## Models and routes  (from config/ai-routes.php and the registry)
account → mid → frontier
billing → local-billing-ft (billing@production) → mid → frontier
howto → local → mid → frontier
policy → mid → frontier
safety → frontier

## Quality  (from eval:scorecard, last 30 days)
golden pass 87.5% · citations valid 95% · disputes escalated no data · false refusals 5% · p95 time to first token 5.36 s

## Fairness  (from eval:fairness)
Run 2026-11-12 on 5 base cases, tolerance 2.0 points. tier: no evidence of disparity · english: not measured (no case had a value for its measure) · variation sets not yet reviewed by a person: tier, english

## Data  (from the data map)
local: region local · retention 0 days · trains on API data no · personal data sent as is (own hardware)
local-70b: region local · retention 0 days · trains on API data no · personal data sent as is (own hardware)
frontier: region unknown (not set) · retention unknown (not set) · trains on API data no · personal data redacted before the call
vision: region local · retention 0 days · trains on API data no · personal data sent as is (own hardware)
local-billing-ft: region local · retention 0 days · trains on API data no · personal data sent as is (own hardware)
mid-in-region: region unknown (not set) · retention 0 days · trains on API data no · personal data sent as is (own hardware)
Conversations are used for training only when a tenant has turned that setting on (default off), and never for a user who excluded theirs (Chapter 40).

## Controls
- Injection: untrusted text wrapped in random-token tags, a canary, quarantined extraction, output checks (Chapter 32).
- Never-gates on every answer: forbidden_facts, injection_followed, schema_valid, claims_to_be_human.
- Tool gate: every tool call is validated, scoped to the signed-in customer and audited; approval by a person for change_billing_email, issue_credit, retry_payment (Chapter 33).
- Goodwill credits are decided by policy (GoodwillCreditPolicy): up to $50.00, 1 per 90 days.
- Redaction before any hosted call (Chapter 34); budgets with a kill switch (Chapter 36).
- Sensitive-content routing before the assistant answers: safety concerns to a person with priority, out-of-scope requests declined with a way to a person, false refusals escalated and logged.

## Disclosure
- **Every assistant conversation** starts with a visible line saying the customer is talking to Ledgerly's AI assistant, with a link to a person that stays on the page for the whole conversation (`resources/views/components/assistant-disclosure.blade.php`; tested in `tests/Feature/Ai/DisclosureTest.php`).
- **Every answer**: the system prompt says the assistant never claims or implies it is a person, and the `claims_to_be_human` never-gate checks every golden answer and every live one (`App\Evals\NeverGates`).
- **Drafts a support agent reviews and sends** are not labeled. The person who sends a reply is responsible for it. This is a policy choice: a team that decides otherwise changes this line and the reply template, and the feature card follows.
- **Content shared outside the app** (exports, emails the assistant helped write) carries no label today; review per market where a rule requires one.
- **The privacy notice** is the help article "The AI assistant and your data" (`resources/help/security/ai-assistant-and-your-data.md`): that the assistant uses AI models, which data goes to which kind of provider, and whether conversations may be used for training.
- **The law.** As of November 2026, Article 50 of the EU AI Act asks that people are told they are interacting with an AI system unless that is obvious; other jurisdictions have their own rules. This page is not legal advice: each market is checked with whoever advises the team and recorded on the companion site's regulatory references page.

## Known limitations and owner
- It answers from the help center and from the customer's own invoices, plans and tickets. It does not know anything else about the customer.
- It can be wrong in fluent, confident language. Answers show their sources; money figures should be checked against the invoice.
- Quality is measured on a golden set of fictional cases, which is smaller than real traffic. Fairness is measured on synthetic variations along five dimensions (name, region, English, language, plan tier); a dimension nobody varied has not been tested.
- The variation sets and the holding messages for safety concerns are drafts: they need review by the people who know the customers and by staff trained for those conversations before a deployment relies on them.
- Fine-tuned models have only been trained at small scales here; see the decision records in `docs/adr/`.

Owner: platform team. Open issues: link them here.
