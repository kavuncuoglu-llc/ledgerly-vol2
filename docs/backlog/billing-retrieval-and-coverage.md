# Billing backlog from `eval:failure-modes`

Written by `php artisan eval:failure-modes --role=local --category=billing --since=30d` on 2026-11-11 against `llama3.1:8b` (native Ollama) with the reranker and the query rewriter off, and kept here as the Chapter 38 backlog: the failing billing cases that fine-tuning cannot fix. `--from=storage/eval/last-failure-modes.json` sorts the same run again without calling the model. The command writes these rows to `storage/eval/backlog.jsonl` (git-ignored: on a real app they are customers' questions; these are the golden set's own fictional ones). Rerun it after retrieval changes and replace this file.

## Retrieval misses (15)

Goes to retrieval tuning (Volume 1, Chapter 16): the expected chunk was not among the passages the model was shown, so no amount of training could have used it. Grouped by the chunk that should have been retrieved.

| Expected chunk | Cases | Cases |
|---|---|---|
| `help:billing/late-fees#how-the-fee-is-calculated` | 11 | `billing-late-fee-014`, `billing-late-fee-015`, `billing-late-fee-brightwell-1`, `billing-late-fee-cinder-1`, `billing-late-fee-hollis-1`, `billing-late-fee-umberfield-1` … |
| `help:payments/payment-plans#what-changes-while-a-plan-is-active` | 1 | `billing-help-037` |
| `help:billing/subscription-refunds#subscription-refunds` | 1 | `billing-help-042` |
| `help:billing/workspace-billing#workspaces-per-plan` | 1 | `billing-help-075` |
| `help:billing/late-fee-settings#per-invoice` | 1 | `billing-help-092` |

The late-fee explanation leads: questions that name an invoice ("why is there a late fee on INV-4471?") are matched on the invoice number and the word "fee" to other articles, and the passage that explains how the fee is calculated is not in the top five. A query rewrite that drops the invoice number before retrieval, or a reranker, are the first things to try; both were off for this run.

## Coverage gaps (0)

Goes to the help center as an article request: the case expects a decline and the model answered. None in this run.

