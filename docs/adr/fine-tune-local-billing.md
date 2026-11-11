# Fine-tune the local model for billing explanations

Status: accepted, pilot · Owner: support-AI team · Review: after two iterations or 90 days

## Context

Billing is about 30% of support traffic (Chapter 38's illustrative figure; read your own from `ai:cascade-report`). Data-residency tenants must have billing questions answered on Ledgerly's own hardware (Chapter 34), so for them routing up a tier is not an option at any price. On cost alone the answer would be to wait: Figure 38-7 puts the monthly cost of a fine-tune near $1,500 against a benefit near $375 at 30,000 conversations a month, and after Chapter 37's cascade the token saving is almost nothing. The requirement is what justifies the pilot.

Failure analysis, run on this repository on 2026-11-11: `php artisan eval:failure-modes --role=local --category=billing --since=30d`, `llama3.1:8b` through native Ollama, the golden set's 41 billing cases at this tag (32 written by people, 9 synthetic; no promoted production failures yet), the reranker and the query rewriter off (the dev setup on one machine). 9 of 41 cases pass every quality gate (22%, against the scorecard's 95% floor). The 32 that fail sort as:

| Bucket | Cases | Share | Goes to |
| --- | --- | --- | --- |
| behavior and format | 17 | 53% | fine-tuning (Section 7) |
| retrieval miss | 15 | 47% | retrieval tuning (Volume 1, Chapter 16): `docs/backlog/billing-retrieval-and-coverage.md` |
| arithmetic | 0 | 0% | `calculate_proration` |
| coverage gap | 0 | 0% | help-center article requested |
| unclear | 0 | 0% | a person |

Inside behavior and format: the model did not call `lookup_invoice` for a question about an invoice it could not know 9 times, the reply had the wrong structure (no citation, markdown) 4 times, left out a fact that was in its context 2 times, and handed off a question its sources answer 2 times.

The book's Figure 38-8 prints 63% behavior and format, 16% retrieval and 13% arithmetic. This run has the same first bucket but a smaller share of it, a much larger retrieval share and no arithmetic at all: the retrieval setup is the degraded one (11 of the 15 misses are one article, `late-fees#how-the-fee-is-calculated`, because a question that names an invoice is matched on the invoice number and the word "fee"), and no golden case asks for a computed amount that is not in a tool result. Re-measure with the reranker and a query rewrite on, and with cases that need a proration, before reading the shares as final. (Chapter 39 adds 20 held-out late-fee cases to the golden set; on that set of 61 the retrieval share is 67%, for the same reason.)

## Options

1. Wait; keep the cascade. Cheapest on cost alone; fails the residency requirement.
2. Route residency tenants' billing to `mid` hosted in-region. Rejected: no in-region option meets the DPA terms on file.
3. LoRA fine-tune of the local 8B on billing explanations. Chosen, as a pilot. The order of work changes with the evidence above: the retrieval backlog comes first, because a fine-tune can only fix the failures that are behavior, about half of them in this run, and the pilot's criteria below are stated against those.

## Success criteria

Billing pass rate on local at least 96% (measured with retrieval fixed) · never-gates 0 · injection model-level success not higher than base · tripwire drop at most 2 points on every task · system prompt for billing at least 30% shorter (Chapter 40 gives the fine-tuned role its own, shortened system message).

## Plan and upkeep

Dataset: Chapter 40 (`billing-v1`). Training: Chapters 42 and 43. Serving and registry: Chapter 44. Retrain quarterly and on any base-model change. Arithmetic moves to the `calculate_proration` tool (a plan change prorated by the days left, a late fee at 1.5% a month prorated by day, nothing under 1.00); retrieval misses go to the retrieval backlog, coverage gaps to the help center. Rerun `eval:failure-modes` after every retrieval change and before every retrain: it is how this record's evidence is refreshed.

## Exit

If two iterations miss the success criteria, stop and record why.
