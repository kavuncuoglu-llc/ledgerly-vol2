# Acceptable use: how the assistant stays inside a model's use policy

Status: draft for the fictional company's legal and security reviewers · Owner: platform team · Review: 2027-02-11

Open-weight models from the Llama family come with an acceptable use policy that the licensee has to follow. This page says where each part of the policy meets a control in Ledgerly's assistant. It maps; it does not interpret. Read the policy text recorded in `config/ai-licenses.php` for the version you use.

| What the policy asks of a deployment | Where Ledgerly meets it |
| --- | --- |
| No use that harms people or helps others harm them | Safety concerns go to a person before the assistant answers (`SensitiveContentRouter`); the assistant cannot take account actions without a person's approval (the tool gate, Chapter 33) |
| No deception about what the system is | The disclosure at the start of every conversation, the system prompt rule, and the `claims_to_be_human` never-gate (Chapter 51, Topic 4) |
| No generating or processing personal data unlawfully | Redaction before a hosted call and the data map (Chapter 34) |
| No use against the owner's own interests or rights of others | Injection defences (Chapter 32) and the never-gates (Chapter 29) |

When a model, an adapter or a distilled student is handed to someone else to run, a second set of terms applies. Change `use` in `config/ai-licenses.php` to `distributed-weights` first and repeat the review.
