# Disclosure: telling people they are talking to an AI

Status: draft for the fictional company's legal reviewers · Owner: platform team · Review: 2027-02-11

- **Every assistant conversation** starts with a visible line saying the customer is talking to Ledgerly's AI assistant, with a link to a person that stays on the page for the whole conversation (`resources/views/components/assistant-disclosure.blade.php`; tested in `tests/Feature/Ai/DisclosureTest.php`).
- **Every answer**: the system prompt says the assistant never claims or implies it is a person, and the `claims_to_be_human` never-gate checks every golden answer and every live one (`App\Evals\NeverGates`).
- **Drafts a support agent reviews and sends** are not labeled. The person who sends a reply is responsible for it. This is a policy choice: a team that decides otherwise changes this line and the reply template, and the feature card follows.
- **Content shared outside the app** (exports, emails the assistant helped write) carries no label today; review per market where a rule requires one.
- **The privacy notice** is the help article "The AI assistant and your data" (`resources/help/security/ai-assistant-and-your-data.md`): that the assistant uses AI models, which data goes to which kind of provider, and whether conversations may be used for training.
- **The law.** As of November 2026, Article 50 of the EU AI Act asks that people are told they are interacting with an AI system unless that is obvious; other jurisdictions have their own rules. This page is not legal advice: each market is checked with whoever advises the team and recorded on the companion site's regulatory references page.
